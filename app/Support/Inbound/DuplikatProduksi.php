<?php

namespace App\Support\Inbound;

use App\Models\InboundDetail;
use App\Models\InboundHeader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Menjaga satu RMO + batch tidak masuk dua kali.
 *
 * KEJADIAN YANG MELAHIRKAN KELAS INI
 * ----------------------------------
 * IN-260910-001 dan IN-260910-002 tersimpan berurutan dengan RMO ID11_1001
 * dan batch yang sama persis. Berkas yang sama diunggah dua kali — mudah
 * terjadi: layar pratinjau tidak berkata apa-apa, penyimpanan berhasil, dan
 * pesan hijaunya sama meyakinkannya dengan unggahan pertama.
 *
 * Akibatnya bukan sekadar dokumen kembar. Palet dari dokumen kedua ikut naik
 * rak dan ikut diverifikasi, lalu stok bertambah DUA KALI untuk barang yang
 * hanya dibuat sekali. Tidak ada satu pun layar yang akan mengeluh, karena
 * setiap langkahnya sah bila dilihat sendiri-sendiri.
 *
 * KUNCINYA RMO + BATCH, BUKAN SALAH SATU
 * --------------------------------------
 * Satu RMO lazim berisi banyak batch — ID11_1001 pada contoh di atas memuat
 * enam. Mengunci pada RMO saja akan menolak sisa batch yang memang belum
 * pernah masuk. Mengunci pada batch saja akan menolak nomor batch yang
 * kebetulan berulang di RMO yang berbeda. Yang menandai "baris produksi yang
 * sama" adalah pasangannya.
 *
 * TIGA KEADAAN, BUKAN DUA
 * -----------------------
 * Menolak mentah-mentah membuat Produksi buntu ketika yang salah memang
 * berkasnya. Membiarkan menimpa apa saja jauh lebih buruk: kalau paletnya
 * sudah naik rak atau stoknya sudah aktif, menimpa membuat catatan sistem
 * berbeda dari barang yang benar-benar berdiri di gudang — dan tidak ada yang
 * akan tahu. Karena itu ada keadaan ketiga: BISA_DITIMPA hanya selama belum
 * ada satu pun palet dari baris itu yang disentuh Operator.
 *
 * Pemeriksaannya per BARIS (RMO + batch), bukan per dokumen. Dokumen yang
 * sebagian paletnya sudah naik rak tetap boleh ditimpa pada baris yang belum
 * — yang dijaga adalah barangnya, bukan berkasnya.
 */
class DuplikatProduksi
{
    /** Belum pernah masuk — aman disimpan. */
    public const BEBAS = 'bebas';

    /** Sudah ada, tetapi belum disentuh Operator — boleh ditimpa. */
    public const BISA_DITIMPA = 'bisa_ditimpa';

    /** Sudah ada DAN sudah naik rak / diverifikasi — tidak boleh ditimpa. */
    public const TERKUNCI = 'terkunci';

    /**
     * Menandai baris rencana dengan keadaan duplikatnya.
     *
     * Hanya baris berstatus 'siap' yang diperiksa: baris yang sudah gagal
     * karena SKU-nya tidak dikenal tidak akan disimpan apa pun keadaannya,
     * dan memberinya lencana duplikat hanya menambah kebisingan di layar.
     *
     * @param  list<array<string, mixed>>  $rows  hasil ProductionSheet::plan()['rows']
     * @return list<array<string, mixed>>
     */
    public function tandai(?int $warehouseId, array $rows): array
    {
        $peta = $this->cari($warehouseId, $rows);

        return array_map(function (array $row) use ($peta) {
            $row['duplikat'] = null;

            if (($row['status'] ?? null) !== 'siap') {
                return $row;
            }

            $row['duplikat'] = $peta[self::kunci($row['production_order_no'] ?? null, $row['batch_no'] ?? null)] ?? null;

            return $row;
        }, $rows);
    }

    /**
     * Ringkasan untuk layar pratinjau.
     *
     * @param  list<array<string, mixed>>  $rows  sudah lewat tandai()
     * @return array{bisa_ditimpa:int, terkunci:int}
     */
    public static function ringkas(array $rows): array
    {
        $hitung = fn (string $keadaan) => count(array_filter(
            $rows,
            fn (array $r) => ($r['duplikat']['keadaan'] ?? null) === $keadaan,
        ));

        return [
            'bisa_ditimpa' => $hitung(self::BISA_DITIMPA),
            'terkunci' => $hitung(self::TERKUNCI),
        ];
    }

    /**
     * Membuang palet lama dari baris yang ditimpa.
     *
     * Dijalankan DI DALAM transaksi penyimpanan dokumen baru, bukan sebelumnya:
     * kalau penyimpanan gagal di tengah jalan, palet lama harus kembali utuh.
     * Menghapusnya lebih dulu "supaya bersih" akan meninggalkan gudang tanpa
     * kedua-duanya.
     *
     * @param  list<array{production_order_no:?string, batch_no:?string}>  $baris
     * @return array<string, int> nomor dokumen lama => jumlah palet yang dibuang
     */
    public function buang(?int $warehouseId, array $baris): array
    {
        $kunci = array_map(fn (array $r) => self::kunci($r['production_order_no'] ?? null, $r['batch_no'] ?? null), $baris);

        if ($kunci === []) {
            return [];
        }

        $lama = $this->detailPadaKunci($warehouseId, $kunci)->get();

        $dibuang = [];
        $headerTersentuh = [];

        foreach ($lama as $detail) {
            // Palet yang sudah disentuh TIDAK ikut dibuang, sekalipun ia
            // kebetulan berada di baris yang ditimpa. periksa() sudah
            // menandainya TERKUNCI dan pemanggilnya tidak akan mengirimkannya
            // ke sini — pemeriksaan ini yang kedua, dan sengaja: satu-satunya
            // hal yang menahan stok ganda tidak boleh bergantung pada satu
            // pagar saja.
            if ($detail->location_id !== null || $detail->putaway_at !== null || $detail->is_verified) {
                continue;
            }

            $dibuang[$detail->inbound_header_id] = ($dibuang[$detail->inbound_header_id] ?? 0) + 1;
            $headerTersentuh[$detail->inbound_header_id] = true;
            $detail->delete();
        }

        return $this->rapikanHeader(array_keys($headerTersentuh), $dibuang);
    }

    /** Kunci gabungan RMO + batch, dinormalkan supaya spasi & huruf besar tidak lolos. */
    public static function kunci(?string $rmo, ?string $batch): string
    {
        return mb_strtoupper(trim((string) $rmo)).'||'.mb_strtoupper(trim((string) $batch));
    }

    /* --------------------------------------------------------------- Dalam */

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{keadaan:string, dokumen:string, status:string, palet:int, tersentuh:int}>
     */
    private function cari(?int $warehouseId, array $rows): array
    {
        $kunci = [];

        foreach ($rows as $row) {
            if (($row['status'] ?? null) === 'siap') {
                $kunci[] = self::kunci($row['production_order_no'] ?? null, $row['batch_no'] ?? null);
            }
        }

        $kunci = array_values(array_unique($kunci));

        if ($kunci === []) {
            return [];
        }

        $peta = [];

        foreach ($this->detailPadaKunci($warehouseId, $kunci)->with('header:id,document_number,status')->get() as $detail) {
            $k = self::kunci($detail->production_order_no, $detail->batch_no);

            $tersentuh = $detail->location_id !== null || $detail->putaway_at !== null || $detail->is_verified;

            if (! isset($peta[$k])) {
                $peta[$k] = [
                    'keadaan' => self::BISA_DITIMPA,
                    'dokumen' => $detail->header?->document_number ?? '—',
                    'status' => InboundHeader::STATUS_LABELS[$detail->header?->status] ?? '—',
                    'palet' => 0,
                    'tersentuh' => 0,
                ];
            }

            $peta[$k]['palet']++;

            if ($tersentuh) {
                $peta[$k]['tersentuh']++;
                $peta[$k]['keadaan'] = self::TERKUNCI;
            }

            // Baris yang sama muncul di LEBIH DARI SATU dokumen lama (berkas
            // pernah diunggah tiga kali) tetap satu entri, dan nomor dokumen
            // yang ditampilkan adalah yang terkunci — itulah yang menjelaskan
            // kenapa barisnya tidak bisa ditimpa.
            if ($tersentuh) {
                $peta[$k]['dokumen'] = $detail->header?->document_number ?? '—';
                $peta[$k]['status'] = InboundHeader::STATUS_LABELS[$detail->header?->status] ?? '—';
            }
        }

        return $peta;
    }

    /**
     * Palet milik kunci RMO+batch tertentu, dibatasi gudang yang sama.
     *
     * Dibatasi gudang karena dua pabrik boleh saja memakai penomoran RMO yang
     * sama tanpa hubungan apa pun; menolaknya lintas gudang akan memblokir
     * produksi yang sah.
     *
     * @param  list<string>  $kunci
     */
    private function detailPadaKunci(?int $warehouseId, array $kunci): Builder
    {
        return InboundDetail::query()
            ->whereHas('header', fn ($q) => $q
                ->when($warehouseId !== null, fn ($w) => $w->where('warehouse_id', $warehouseId)))
            // Perbandingan dilakukan di basis data dengan normalisasi yang
            // SAMA dengan kunci() di PHP. Menyaring di PHP berarti menarik
            // seluruh tabel palet ke memori setiap kali ada berkas diunggah.
            ->whereIn(
                DB::raw("UPPER(TRIM(COALESCE(production_order_no, ''))) || '||' || UPPER(TRIM(batch_no))"),
                $kunci,
            );
    }

    /**
     * Membereskan dokumen lama setelah paletnya dibuang.
     *
     * Dua hal yang mudah terlupakan:
     *   1. Dokumen yang kehilangan SELURUH paletnya tidak boleh tertinggal
     *      sebagai baris kosong di Riwayat Produksi — ia menghitung 0 palet
     *      dan tidak bisa diapa-apakan siapa pun.
     *   2. Dokumen yang sisa paletnya ternyata sudah punya lokasi semua HARUS
     *      naik ke tahap verifikasi. Kalau tidak, ia tertinggal selamanya di
     *      daftar PDN tanpa satu pun palet yang bisa ditempatkan.
     *
     * @param  list<int>  $headerIds
     * @param  array<int, int>  $dibuang
     * @return array<string, int>
     */
    private function rapikanHeader(array $headerIds, array $dibuang): array
    {
        $hasil = [];

        foreach (InboundHeader::whereIn('id', $headerIds)->get() as $header) {
            $hasil[$header->document_number] = $dibuang[$header->id] ?? 0;

            if ($header->details()->doesntExist()) {
                $header->delete();   // soft delete — riwayatnya tetap terbaca

                continue;
            }

            if ($header->status === InboundHeader::STATUS_PUTAWAY_PENDING && $header->isFullyPlaced()) {
                $header->update(['status' => InboundHeader::STATUS_VERIFICATION_PENDING]);
            }
        }

        return $hasil;
    }
}
