<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\InboundHeader;
use App\Models\Notification;
use App\Models\Warehouse;
use App\Support\Activity;
use App\Support\DocumentNumber;
use App\Support\Inbound\DuplikatProduksi;
use App\Support\Inbound\ProductionSheet;
use App\Support\Inventory\StockActivator;
use App\Support\Notifier;
use App\Support\Permission;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/**
 * Input Produksi — PRD §6.3 F-INB-01: unggah berkas produksi, pratinjau,
 * lalu simpan sebagai dokumen inbound.
 *
 * Tahap sesudahnya ada di controller sendiri: riwayat & koreksi qty
 * (InboundHistoryController), put-away (PutawayController), dan verifikasi
 * Maker-Checker (InboundVerificationController).
 */
class InboundController extends Controller
{
    /** Berkas produksi sementara, disimpan di disk lokal di luar public. */
    private const TEMP_DIR = 'inbound';

    /**
     * Sejauh mana tanggal produksi boleh dimundurkan.
     *
     * Tanggal produksi boleh mundur karena produksi tadi malam lazim baru
     * sempat diinput pagi ini — dan tanggal itu BUKAN sekadar keterangan: ia
     * menjadi tanggal produksi tiap batch di rak, dan kedaluwarsanya dihitung
     * dari situ (StockActivator). Memaksanya selalu hari ini berarti memberi
     * umur simpan lebih panjang daripada yang sebenarnya.
     *
     * Batas ini bukan aturan bisnis, melainkan penangkap salah ketik. Input
     * produksi yang tertunda lebih dari tiga bulan bukan "kemarin belum
     * sempat" — hampir selalu tahun atau bulannya yang salah diketik, dan
     * akibatnya adalah tanggal kedaluwarsa yang keliru dan tidak akan pernah
     * terlihat lagi setelah dokumennya tersimpan.
     */
    private const MUNDUR_MAKS_HARI = 90;

    /**
     * F-INB-01: Form Input Produksi.
     *
     * DATA CONTRACT (view: wms.inbound.create)
     * ----------------------------------------
     * $documentNumber : string    — nomor dokumen yang AKAN dipakai (pratinjau)
     * $productionDate : Carbon    — tanggal hari ini
     * $warehouses     : Collection<Warehouse>
     *
     * Nomor dokumen & tanggal dibangkitkan sistem, tidak diketik Tim Produksi.
     * Nomor di layar ini baru pratinjau; nomor final dikunci saat menyimpan,
     * karena bisa saja ada dokumen lain tersimpan lebih dulu di sela-selanya.
     */
    public function create(Request $request): View
    {
        // Hanya gudang yang punya lini produksi. Bagi Pekanbaru dan Surabaya
        // daftarnya kosong — dan layarnya mengatakan itu apa adanya, bukan
        // menyodorkan dropdown yang tidak bisa dipilih apa pun.
        $gudang = WarehouseScope::options($request->user())->where('has_production', true)->values();

        return view('wms.inbound.create', [
            'documentNumber' => DocumentNumber::peek(DocumentNumber::PREFIX_INBOUND, 'inbound_headers'),
            'productionDate' => now(),
            'tanggalTerawal' => now()->subDays(self::MUNDUR_MAKS_HARI)->toDateString(),
            'warehouses' => $gudang,
        ]);
    }

    /**
     * Aturan tanggal produksi — sama persis di pratinjau dan penyimpanan.
     *
     * Ditulis sekali karena layar pratinjau mengirim ulang tanggalnya sebagai
     * input tersembunyi, dan input tersembunyi tetap saja input. Kalau
     * aturannya hanya dipasang di pratinjau, tanggal apa pun bisa masuk lewat
     * langkah kedua.
     *
     * @return array<string, list<string>>
     */
    private function aturanTanggalProduksi(): array
    {
        return [
            'production_date' => [
                'required',
                'date',
                // Tidak boleh di masa depan: barang yang belum dibuat tidak
                // bisa naik rak, dan tanggal maju memberi umur simpan yang
                // tidak pernah dimiliki batch itu.
                'before_or_equal:'.now()->toDateString(),
                'after_or_equal:'.now()->subDays(self::MUNDUR_MAKS_HARI)->toDateString(),
            ],
        ];
    }

    /** @return array<string, string> */
    private function pesanTanggalProduksi(): array
    {
        return [
            'production_date.before_or_equal' => 'Tanggal produksi tidak boleh melewati hari ini.',
            'production_date.after_or_equal' => 'Tanggal produksi paling jauh '.self::MUNDUR_MAKS_HARI
                .' hari ke belakang. Periksa lagi bulan dan tahunnya — kedaluwarsa batch dihitung dari tanggal ini.',
        ];
    }

    /**
     * Gudang tujuan dokumen produksi harus sah DAN benar-benar berproduksi.
     *
     * Dua pemeriksaan berbeda yang mudah dikira satu: `warehouse_id` boleh
     * jadi memang gudang milik user ini (lolos WarehouseScope), tetapi kalau
     * gudang itu hanya menyimpan stok, dokumen produksi di sana adalah barang
     * yang tidak pernah dibuat siapa pun.
     */
    private function pastikanGudangProduksi(Request $request): ?RedirectResponse
    {
        WarehouseScope::assert($request->integer('warehouse_id'), $request->user());

        $gudang = Warehouse::find($request->integer('warehouse_id'));

        if ($gudang === null || ! $gudang->has_production) {
            return redirect()->route('wms.inbound.create')->with('error', sprintf(
                'Gudang %s tidak memiliki lini produksi. Stok masuk ke sana lewat transfer dari Karawang, bukan input produksi.',
                $gudang?->name ?? 'yang dipilih'
            ));
        }

        return null;
    }

    /**
     * F-INB-01 langkah 4: baca berkas, pecah jadi palet, tampilkan pratinjau.
     *
     * TIDAK menyentuh basis data. Berkas disimpan sementara agar tahap simpan
     * bisa membacanya ulang; berkas itu dihapus begitu disimpan atau dibatalkan
     * sehingga tidak menumpuk di server.
     */
    public function previewExcel(Request $request): View|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
        ] + $this->aturanTanggalProduksi(),
            $this->pesanTanggalProduksi(),
            ['file' => 'berkas Excel', 'production_date' => 'tanggal produksi'],
        );

        if ($tolak = $this->pastikanGudangProduksi($request)) {
            return $tolak;
        }

        // Nama berkas dibangkitkan sendiri, bukan memakai nama asli dari
        // pengguna, agar tidak ada jalur yang bisa diarahkan ke tempat lain.
        $token = Str::uuid()->toString();
        $extension = ImportController::ekstensi($request);
        $stored = self::TEMP_DIR.'/'.$token.'.'.$extension;

        $saved = Storage::disk('local')->putFileAs(
            self::TEMP_DIR,
            $request->file('file'),
            basename($stored)
        );

        // Kegagalan MENULIS diperiksa terpisah dari kegagalan MEMBACA, agar
        // folder yang tidak bisa ditulis tidak dilaporkan sebagai berkas rusak.
        if ($saved === false || ! Storage::disk('local')->exists($stored)) {
            return redirect()->route('wms.inbound.create')->with(
                'error',
                'Berkas gagal disimpan sementara di server. Periksa izin tulis pada folder storage/app/private/'.self::TEMP_DIR.'.'
            );
        }

        try {
            $plan = (new ProductionSheet)->plan(Storage::disk('local')->path($stored));
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($stored);

            return redirect()->route('wms.inbound.create')->with('error', $e->getMessage());
        }

        // Duplikat ditandai DI PRATINJAU, bukan baru ketahuan setelah simpan.
        // Yang menyimpan lebih dulu lalu diberi tahu "ternyata sudah ada"
        // sudah terlanjur membuat nomor dokumen yang harus dibereskan orang.
        $rows = (new DuplikatProduksi)->tandai($request->integer('warehouse_id'), $plan['rows']);

        return view('wms.inbound.preview', [
            'token' => $token,
            'extension' => $extension,
            'originalName' => $request->file('file')->getClientOriginalName(),
            'warehouse' => Warehouse::find($request->integer('warehouse_id')),
            'documentNumber' => DocumentNumber::peek(DocumentNumber::PREFIX_INBOUND, 'inbound_headers'),
            'productionDate' => Carbon::parse($request->input('production_date')),
            'rows' => $rows,
            'summary' => $this->ringkasanPratinjau($plan['summary'], $rows),
        ]);
    }

    /**
     * Angka yang ditampilkan layar pratinjau — bukan angka mentah pembacaan.
     *
     * plan()['summary']['siap'] berarti "barisnya TERBACA utuh", bukan "baris
     * ini akan tersimpan": pemeriksaan duplikat baru berjalan sesudahnya. Dua
     * baris yang terkunci karena sudah pernah masuk tetap terhitung siap,
     * sehingga layar pernah menulis "Siap Disimpan 2" tepat di atas peringatan
     * "2 baris tidak akan disimpan" — dua angka yang saling membantah pada
     * layar yang sama, dan yang membaca tidak punya cara tahu mana yang benar.
     *
     * Yang dihitung di sini adalah apa yang BENAR-BENAR akan tersimpan bila
     * Submit ditekan sekarang, berikut berapa jadinya bila "Timpa data yang
     * sudah ada" dicentang. Keduanya dikirim sekaligus karena centangnya
     * berpindah di peramban tanpa memuat ulang halaman.
     *
     * @param  array<string, int>  $ringkas  plan()['summary']
     * @param  list<array<string, mixed>>  $rows  sudah lewat DuplikatProduksi::tandai()
     * @return array<string, int>
     */
    private function ringkasanPratinjau(array $ringkas, array $rows): array
    {
        $hitung = function (callable $lolos) use ($rows): array {
            $baris = 0;
            $palet = 0;

            foreach ($rows as $row) {
                if (($row['status'] ?? null) !== 'siap' || ! $lolos($row['duplikat']['keadaan'] ?? null)) {
                    continue;
                }

                $baris++;
                $palet += count($row['pallets'] ?? []);
            }

            return [$baris, $palet];
        };

        // Tanpa centang: baris duplikat mana pun dilewati, termasuk yang
        // sebenarnya boleh ditimpa.
        [$baris, $palet] = $hitung(fn (?string $keadaan) => $keadaan === null);

        // Dengan centang: hanya yang TERKUNCI yang tetap dilewati.
        [$barisTimpa, $paletTimpa] = $hitung(fn (?string $keadaan) => $keadaan !== DuplikatProduksi::TERKUNCI);

        return $ringkas + DuplikatProduksi::ringkas($rows) + [
            'akan_disimpan' => $baris,
            'palet_disimpan' => $palet,
            'akan_disimpan_timpa' => $barisTimpa,
            'palet_disimpan_timpa' => $paletTimpa,
        ];
    }

    /**
     * F-INB-01 langkah 6: simpan dokumen produksi.
     *
     * Berkas Excel DIHAPUS setelah disimpan — sistem tidak menyimpan berkas
     * mentah, hanya hasil pembacaannya. Berkas dibaca ulang di sini (bukan
     * mempercayai kiriman dari layar pratinjau) supaya angka yang tersimpan
     * benar-benar berasal dari berkas, bukan dari nilai yang bisa diubah
     * lewat peramban.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'extension' => ['required', 'in:xlsx,xls'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            // Menimpa harus DIMINTA, tidak pernah menjadi bawaan. Yang
            // mengunggah ulang berkas yang sama karena mengira yang pertama
            // gagal tidak sedang meminta apa pun ditimpa.
            'timpa' => ['nullable', 'boolean'],
        ] + $this->aturanTanggalProduksi(),
            $this->pesanTanggalProduksi(),
            ['production_date' => 'tanggal produksi'],
        );

        // Diperiksa LAGI di sini, bukan hanya di previewExcel(): layar
        // pratinjau mengirim ulang warehouse_id sebagai input tersembunyi,
        // dan input tersembunyi tetap saja input.
        if ($tolak = $this->pastikanGudangProduksi($request)) {
            return $tolak;
        }

        $stored = self::TEMP_DIR.'/'.$validated['token'].'.'.$validated['extension'];

        if (! Storage::disk('local')->exists($stored)) {
            return redirect()->route('wms.inbound.create')
                ->with('error', 'Berkas sementara sudah tidak tersedia. Silakan unggah ulang.');
        }

        try {
            $plan = (new ProductionSheet)->plan(Storage::disk('local')->path($stored));
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($stored);

            return redirect()->route('wms.inbound.create')->with('error', $e->getMessage());
        }

        $penjaga = new DuplikatProduksi;
        $rows = $penjaga->tandai((int) $validated['warehouse_id'], $plan['rows']);

        $siap = collect($rows)->where('status', 'siap');
        $bolehTimpa = (bool) ($validated['timpa'] ?? false);

        // Tiga tumpukan, tiga nasib berbeda — dan ketiganya disebut di pesan
        // hasil. Baris yang hilang tanpa penjelasan adalah cara tercepat
        // membuat orang mengunggah ulang berkas yang sama sekali lagi.
        $baru = $siap->filter(fn (array $r) => $r['duplikat'] === null);
        $ditimpa = $siap->filter(fn (array $r) => ($r['duplikat']['keadaan'] ?? null) === DuplikatProduksi::BISA_DITIMPA);
        $terkunci = $siap->filter(fn (array $r) => ($r['duplikat']['keadaan'] ?? null) === DuplikatProduksi::TERKUNCI);

        if (! $bolehTimpa) {
            $terkunci = $terkunci->merge($ditimpa);
            $ditimpa = collect();
        }

        $ready = $baru->merge($ditimpa);

        if ($ready->isEmpty()) {
            Storage::disk('local')->delete($stored);

            return redirect()->route('wms.inbound.create')
                ->with('error', $this->pesanTidakAdaYangBisaDisimpan($siap, $terkunci, $bolehTimpa));
        }

        $dibuang = [];

        $header = DB::transaction(function () use ($ready, $ditimpa, $validated, $request, $penjaga, &$dibuang) {
            // Palet lama dibuang DI DALAM transaksi yang sama dengan penulisan
            // dokumen baru. Kalau penyimpanan gagal setengah jalan, gudang
            // tidak boleh kehilangan kedua-duanya.
            if ($ditimpa->isNotEmpty()) {
                $dibuang = $penjaga->buang((int) $validated['warehouse_id'], $ditimpa->all());
            }

            $header = InboundHeader::create([
                'document_number' => DocumentNumber::reserve(DocumentNumber::PREFIX_INBOUND, 'inbound_headers'),
                'warehouse_id' => $validated['warehouse_id'],
                // Tanggal yang dipilih Tim Produksi, bukan hari ini. Nomor
                // dokumennya tetap memakai tanggal PENCATATAN — keduanya
                // memang menjawab pertanyaan yang berbeda, dan menyamakannya
                // akan menghapus jejak bahwa inputnya terlambat.
                'production_date' => Carbon::parse($validated['production_date'])->toDateString(),
                'status' => InboundHeader::STATUS_PUTAWAY_PENDING,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            // Satu baris Excel menghasilkan satu baris per PALET, bukan satu
            // baris per produk — Operator menerima daftar palet fisik yang
            // siap ditempatkan (PRD §7.1).
            foreach ($ready as $row) {
                foreach ($row['pallets'] as $index => $palletQty) {
                    $header->details()->create([
                        'product_id' => $row['product_id'],
                        'production_order_no' => $row['production_order_no'],
                        'batch_no' => $row['batch_no'],
                        'total_qty' => $row['qty'],
                        'pallet_no' => $index + 1,
                        'pallet_qty' => $palletQty,
                    ]);
                }
            }

            return $header;
        });

        // Berkas mentah tidak disimpan agar tidak menumpuk di server.
        Storage::disk('local')->delete($stored);

        $message = sprintf(
            'Dokumen %s tersimpan: %d baris produksi menjadi %d palet, menunggu PDN.',
            $header->document_number,
            $ready->count(),
            $header->details()->count()
        );

        if ($ditimpa->isNotEmpty()) {
            $message .= sprintf(
                ' %d baris menimpa data lama di dokumen %s — %d palet lama dibuang.',
                $ditimpa->count(),
                implode(', ', array_keys($dibuang)) ?: '—',
                array_sum($dibuang),
            );
        }

        if ($terkunci->isNotEmpty()) {
            $message .= sprintf(
                ' %d baris DILEWATI karena RMO + batch-nya sudah pernah masuk%s.',
                $terkunci->count(),
                $bolehTimpa ? ' dan paletnya sudah naik rak atau sudah diverifikasi' : '',
            );
        }

        if ($plan['summary']['gagal'] > 0) {
            $message .= sprintf(' %d baris dilewati karena datanya bermasalah.', $plan['summary']['gagal']);
        }

        Activity::record(
            ActivityLog::INBOUND_CREATE,
            sprintf(
                'Input produksi %s — %d baris menjadi %d palet.%s',
                $header->document_number,
                $ready->count(),
                $header->details()->count(),
                $ditimpa->isNotEmpty()
                    ? sprintf(' Menimpa %d baris dari dokumen %s.', $ditimpa->count(), implode(', ', array_keys($dibuang)))
                    : '',
            ),
            $header,
            $header->warehouse_id,
            [
                'dokumen' => $header->document_number,
                'baris' => $ready->count(),
                'palet' => $header->details()->count(),
                'dilewati' => $plan['summary']['gagal'],
                'ditimpa' => $ditimpa->count(),
                'dokumen_ditimpa' => $dibuang,
                'duplikat_dilewati' => $terkunci->count(),
            ],
        );

        Notifier::toPermission(
            Permission::INBOUND_PUTAWAY,
            $header->warehouse_id,
            Notification::PUTAWAY_READY,
            'Barang produksi menunggu naik rak',
            sprintf(
                'Dokumen %s — %d palet siap dinaikkan.',
                $header->document_number,
                $header->details()->count(),
            ),
            route('wms.inbound.putaway'),
            $header,
        );

        return redirect()->route('wms.inbound.history')->with('success', $message);
    }

    /**
     * Kenapa tidak ada satu baris pun yang tersimpan.
     *
     * "Tidak ada baris yang dapat disimpan" saja akan membuat Tim Produksi
     * memperbaiki berkasnya — padahal berkasnya benar, dan yang terjadi
     * adalah berkas itu memang sudah pernah masuk. Mereka akan mengunggahnya
     * lagi, dan lagi.
     *
     * @param  Collection<int, array<string, mixed>>  $siap
     * @param  Collection<int, array<string, mixed>>  $terkunci
     */
    private function pesanTidakAdaYangBisaDisimpan($siap, $terkunci, bool $bolehTimpa): string
    {
        if ($terkunci->isEmpty()) {
            return 'Tidak ada baris yang dapat disimpan. Perbaiki berkas lalu unggah ulang.';
        }

        $dokumen = $terkunci
            ->map(fn (array $r) => $r['duplikat']['dokumen'] ?? null)
            ->filter()
            ->unique()
            ->implode(', ');

        // Dua sebab yang terlihat sama di layar tetapi menuntut tindakan
        // berbeda: yang satu tinggal mencentang "timpa", yang lain tidak bisa
        // diapa-apakan lagi lewat layar ini.
        $adaYangMasihBisaDitimpa = ! $bolehTimpa && $siap->contains(
            fn (array $r) => ($r['duplikat']['keadaan'] ?? null) === DuplikatProduksi::BISA_DITIMPA
        );

        if ($adaYangMasihBisaDitimpa) {
            return sprintf(
                'Seluruh baris pada berkas ini sudah pernah masuk lewat dokumen %s. '
                    .'Kalau memang ingin menggantinya, unggah ulang lalu centang "Timpa data yang sudah ada" di layar pratinjau.',
                $dokumen,
            );
        }

        return sprintf(
            'Seluruh baris pada berkas ini sudah pernah masuk lewat dokumen %s, dan paletnya sudah naik rak atau sudah diverifikasi '
                .'(sudah naik rak atau sudah diverifikasi) sehingga tidak boleh ditimpa. '
                .'Barangnya sudah berdiri di rak dan angkanya sudah dihitung — menimpanya akan membuat catatan sistem '
                .'berbeda dari isi gudang. Kalau ada yang keliru, perbaiki lewat koreksi stok, bukan lewat unggah ulang.',
            $dokumen,
        );
    }

    /** Membatalkan pratinjau: buang berkas sementara agar tidak menumpuk. */
    public function cancelPreview(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'extension' => ['required', 'in:xlsx,xls'],
        ]);

        Storage::disk('local')->delete(self::TEMP_DIR.'/'.$validated['token'].'.'.$validated['extension']);

        return redirect()->route('wms.inbound.create')->with('success', 'Input produksi dibatalkan.');
    }
}
