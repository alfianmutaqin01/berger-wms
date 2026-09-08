<?php

namespace App\Support\Inventory;

use App\Models\InventoryStock;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Penanda batch — permintaan pemilik produk (bukan PRD). Ada tiga, dan
 * ketiganya sengaja berbeda sifat:
 *
 *   MASALAH KUALITAS — murni informasi, tidak menyentuh urutan sama sekali.
 *   KARANTINA        — MENGELUARKAN batch dari pencalonan, sementara.
 *   DAHULUKAN KELUAR — batch tetap dicalonkan, hanya NAIK KE DEPAN antrean.
 *
 * Karantina dan Dahulukan Keluar adalah dua arah yang berlawanan pada sumbu
 * yang sama, jadi keduanya tidak masuk akal menyala bersamaan dalam praktik
 * — tetapi TIDAK dilarang: karantina lepas sendiri, dan begitu lepas, batch
 * bertanda langsung naik ke depan. Melarangnya justru memaksa Logistik
 * mengingat untuk menandai ulang setelah karantina berakhir.
 *
 * SATU BATCH, SATU KEPUTUSAN. Baik karantina maupun masalah kualitas
 * diterapkan ke SELURUH baris `product_id + warehouse_id + batch_no`, bukan
 * satu baris saja — keduanya melekat pada apa yang terjadi saat produksi/
 * pengujian, bukan pada rak tempat sekarang barangnya duduk. Satu batch yang
 * separuh bermasalah dan separuh tidak, tidak masuk akal secara fisik.
 *
 * KARANTINA BUKAN DDP. DDP permanen sampai dikeluarkan manual oleh Manager/
 * Super Admin (App\Http\Controllers\Wms\InventoryController::adjust). Karantina
 * BERBASIS HARI dan lepas SENDIRI — lihat App\Console\Commands\SweepQuarantine.
 * Selama berlaku, statusnya 'quarantine' membuatnya otomatis terlewati FIFO
 * (FifoAllocator menyaring `status = active` secara langsung), sehingga tidak
 * ada satu pun query alokasi yang perlu diubah untuk menegakkan "tidak ikut
 * dijual selama masa tunggu".
 */
class StockQuarantine
{
    /**
     * Menahan satu batch selama sejumlah hari.
     *
     * @return int jumlah baris yang ikut dikarantina
     *
     * @throws RuntimeException
     */
    public function place(InventoryStock $acuan, int $hari, ?string $catatan, int $userId): int
    {
        if ($hari < 1) {
            throw new RuntimeException('Lama karantina minimal 1 hari.');
        }

        return DB::transaction(function () use ($acuan, $hari, $catatan, $userId) {
            $baris = $this->kunciSebatch($acuan);

            if ($baris->isEmpty()) {
                throw new RuntimeException('Baris stok ini sudah tidak ada.');
            }

            $bukanAktif = $baris->first(fn (InventoryStock $s) => ! in_array(
                $s->status, [InventoryStock::STATUS_ACTIVE, InventoryStock::STATUS_QUARANTINE], true
            ));

            if ($bukanAktif !== null) {
                throw new RuntimeException(sprintf(
                    'Batch %s sedang berstatus "%s" — karantina hanya berlaku untuk Good Stock, '.
                    'bukan stok yang sudah rusak/kedaluwarsa.',
                    $acuan->batch_no,
                    $bukanAktif->status_label,
                ));
            }

            $sampai = now()->addDays($hari)->toDateString();

            foreach ($baris as $stok) {
                $stok->forceFill([
                    'status' => InventoryStock::STATUS_QUARANTINE,
                    'quarantine_days' => $hari,
                    'quarantine_until' => $sampai,
                    'quarantined_at' => now(),
                    'quarantined_by' => $userId,
                    'quarantine_note' => $catatan,
                    'quarantine_released_at' => null,
                ])->save();

                $this->catatPergerakan($stok, $userId, sprintf(
                    'KARANTINA: ditahan %d hari sampai %s%s.',
                    $hari,
                    $sampai,
                    $catatan !== null ? ' — '.$catatan : '',
                ));
            }

            return $baris->count();
        });
    }

    /**
     * Melepas karantina lebih awal (mis. QC selesai sebelum jangka waktunya).
     *
     * @return int jumlah baris yang dilepas
     *
     * @throws RuntimeException
     */
    public function release(InventoryStock $acuan, int $userId): int
    {
        return DB::transaction(function () use ($acuan, $userId) {
            $baris = $this->kunciSebatch($acuan)
                ->filter(fn (InventoryStock $s) => $s->status === InventoryStock::STATUS_QUARANTINE);

            if ($baris->isEmpty()) {
                throw new RuntimeException('Batch ini sedang tidak dalam karantina.');
            }

            foreach ($baris as $stok) {
                $stok->forceFill([
                    'status' => InventoryStock::STATUS_ACTIVE,
                    'quarantine_released_at' => now(),
                ])->save();

                $this->catatPergerakan($stok, $userId, 'KARANTINA DIBATALKAN lebih awal — kembali jadi Good Stock.');
            }

            return $baris->count();
        });
    }

    /**
     * Menyalakan/mematikan penanda Masalah Kualitas untuk satu batch.
     *
     * MURNI INFORMASI. Tidak menyentuh `status`, tidak menghalangi FIFO —
     * hanya penanda supaya Logistik tahu batch mana yang pernah bermasalah
     * saat diperiksa.
     *
     * PENANDA INI TIDAK MENAHAN BATCH, dan itu disengaja. Yang menahan sudah
     * ada dan tetap terpisah: KARANTINA untuk tahan sementara, DDP untuk
     * tahan permanen. Kalau penanda ini ikut memblokir FIFO, ada dua jalan
     * berbeda untuk melakukan hal yang sama — dan yang satu tidak punya masa
     * berlaku, catatan alasan, maupun jalur pelepasan seperti karantina.
     *
     * @return array{jumlah:int, nilai:bool} nilai baru setelah ditoggle
     */
    public function toggleQualityIssue(InventoryStock $acuan): array
    {
        return DB::transaction(function () use ($acuan) {
            $baris = $this->kunciSebatch($acuan);
            $nilaiBaru = ! $acuan->has_quality_issue;

            foreach ($baris as $stok) {
                $stok->forceFill(['has_quality_issue' => $nilaiBaru])->save();
            }

            // TIDAK dicatat ke stock_movements: ini murni label tampilan,
            // bukan perubahan yang memengaruhi qty atau kelayakan jual —
            // mencatatnya di ledger stok hanya akan menenggelamkan mutasi
            // yang sungguh-sungguh berarti secara fisik.
            return ['jumlah' => $baris->count(), 'nilai' => $nilaiBaru];
        });
    }

    /**
     * Menandai satu batch supaya KELUAR DULUAN, mendahului batch yang lebih
     * tua — kebalikan karantina.
     *
     * SATU-SATUNYA PENANDA YANG MENGUBAH URUTAN ALOKASI. Penegakannya bukan
     * di sini melainkan di InventoryStock::scopeUrutanKeluar(), yang dipakai
     * ketiga jalur keluar. Kelas ini hanya memasang penandanya.
     *
     * ALASAN WAJIB, dan ini bukan sekadar tata cara: melanggar FIFO akan
     * ditanyakan orang, dan tanpa alasan tertulis penanda yang dimaksudkan
     * sementara berubah jadi keadaan permanen tanpa pemilik.
     *
     * DICATAT KE LEDGER, berbeda dari Masalah Kualitas. Qty-nya memang tidak
     * berubah, tetapi ini keputusan yang MENGUBAH barang mana yang keluar ke
     * pelanggan — persis jenis kejadian yang harus bisa ditelusuri, sama
     * seperti karantina.
     *
     * @return int jumlah baris yang ikut ditandai
     *
     * @throws RuntimeException
     */
    public function prioritize(InventoryStock $acuan, string $alasan, int $userId): int
    {
        if (trim($alasan) === '') {
            throw new RuntimeException('Alasan mendahulukan batch wajib diisi.');
        }

        return DB::transaction(function () use ($acuan, $alasan, $userId) {
            $baris = $this->kunciSebatch($acuan);

            if ($baris->isEmpty()) {
                throw new RuntimeException('Baris stok ini sudah tidak ada.');
            }

            // Batch yang tidak layak jual tidak akan pernah dicalonkan keluar,
            // jadi mendahulukannya adalah penanda yang tidak berarti apa-apa —
            // dan justru menyesatkan orang yang mengira barangnya sudah
            // diprioritaskan. Karantina TIDAK ikut ditolak: ia akan lepas
            // sendiri, dan begitu lepas penanda ini langsung berlaku.
            $takLayak = $baris->first(fn (InventoryStock $s) => in_array(
                $s->status, [InventoryStock::STATUS_DDP, InventoryStock::STATUS_EXPIRED], true
            ));

            if ($takLayak !== null) {
                throw new RuntimeException(sprintf(
                    'Batch %s berstatus "%s" — tidak boleh dijual sama sekali, jadi mendahulukannya tidak ada artinya.',
                    $acuan->batch_no,
                    $takLayak->status_label,
                ));
            }

            $sekarang = now();

            foreach ($baris as $stok) {
                $stok->forceFill([
                    'prioritize_out' => true,
                    'prioritize_reason' => $alasan,
                    'prioritized_at' => $sekarang,
                    'prioritized_by' => $userId,
                    'prioritize_released_at' => null,
                ])->save();

                $this->catatPergerakan($stok, $userId, sprintf(
                    'DAHULUKAN KELUAR: batch ini didahulukan mendahului batch yang lebih tua — %s',
                    $alasan,
                ));
            }

            return $baris->count();
        });
    }

    /**
     * Melepas penanda "Dahulukan Keluar" — batch kembali mengantre menurut
     * umurnya.
     *
     * @param  bool  $olehSistem  true bila dilepas sweep karena batchnya habis,
     *                            bukan oleh keputusan orang
     * @return int jumlah baris yang dilepas
     *
     * @throws RuntimeException
     */
    public function releasePriority(InventoryStock $acuan, ?int $userId, bool $olehSistem = false): int
    {
        return DB::transaction(function () use ($acuan, $userId, $olehSistem) {
            $baris = $this->kunciSebatch($acuan)
                ->filter(fn (InventoryStock $s) => (bool) $s->prioritize_out);

            if ($baris->isEmpty()) {
                throw new RuntimeException('Batch ini sedang tidak ditandai untuk didahulukan.');
            }

            foreach ($baris as $stok) {
                $stok->forceFill([
                    'prioritize_out' => false,
                    'prioritize_released_at' => now(),
                ])->save();

                $this->catatPergerakan($stok, $userId, $olehSistem
                    ? 'DAHULUKAN KELUAR BERAKHIR: batch habis, penanda dilepas otomatis oleh sweep harian.'
                    : 'DAHULUKAN KELUAR DILEPAS — batch kembali mengantre menurut umurnya (FIFO).');
            }

            return $baris->count();
        });
    }

    /* ------------------------------------------------------------- Dalam */

    /**
     * Seluruh baris produk+gudang+batch yang sama, terkunci untuk diubah.
     *
     * @return Collection<int, InventoryStock>
     */
    private function kunciSebatch(InventoryStock $acuan): Collection
    {
        return InventoryStock::query()
            ->where('product_id', $acuan->product_id)
            ->where('warehouse_id', $acuan->warehouse_id)
            ->where('batch_no', $acuan->batch_no)
            ->lockForUpdate()
            ->get();
    }

    private function catatPergerakan(InventoryStock $stok, ?int $userId, string $catatan): void
    {
        // Qty TIDAK berubah — barangnya tetap di rak, hanya statusnya yang
        // bergeser. Tetap dicatat demi jejak audit, mengikuti pola yang sama
        // dengan sweep kedaluwarsa (qty_change 0, ADJUSTMENT).
        StockMovement::create([
            'product_id' => $stok->product_id,
            'location_id' => $stok->location_id,
            'warehouse_id' => $stok->warehouse_id,
            'movement_type' => StockMovement::TYPE_ADJUSTMENT,
            'qty_change' => 0,
            'qty_before' => $stok->qty_available,
            'qty_after' => $stok->qty_available,
            'reference_type' => StockMovement::REF_ADJUSTMENT,
            'reference_id' => $stok->id,
            'batch_no' => $stok->batch_no,
            'notes' => $catatan,
            'user_id' => $userId,
        ]);
    }
}
