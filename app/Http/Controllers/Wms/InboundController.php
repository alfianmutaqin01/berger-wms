<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\Location;
use App\Models\Notification;
use App\Models\Warehouse;
use App\Support\Activity;
use App\Support\DocumentNumber;
use App\Support\FilterTanggal;
use App\Support\Inbound\BinAllocator;
use App\Support\Inbound\DuplikatProduksi;
use App\Support\Inbound\ProductionSheet;
use App\Support\Inventory\StockActivator;
use App\Support\Notifier;
use App\Support\Outbound\PendingAllocationFiller;
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

    public function __construct(private readonly PendingAllocationFiller $pengisi) {}

    /**
     * F-INB-01: Riwayat Input Produksi.
     *
     * DATA CONTRACT (view: wms.inbound.history)
     * -----------------------------------------
     * $documents  : LengthAwarePaginator<InboundHeader> — sudah withCount('details')
     *               dan eager-load warehouse + kolom batch_no tiap detail
     * $warehouses : Collection<Warehouse>
     * $statuses   : array<string, string> — slug status => label
     * $stats      : array{total:int, putaway:int, verifikasi:int, selesai:int}
     * $filters    : array{search:?string, status:?string, warehouse_id:?string,
     *                     from:?string, to:?string}
     *
     * CATATAN: satu dokumen bisa memuat BEBERAPA batch, karena satu berkas
     * produksi berisi banyak baris. Karena itu kolom batch menampilkan daftar
     * unik, bukan satu nilai tunggal seperti pada rancangan mock lama.
     */
    public function historyIndex(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'warehouse_id' => WarehouseScope::resolveFilter($request, $request->user()),
            'from' => FilterTanggal::bersih($request->query('from')),
            'to' => FilterTanggal::bersih($request->query('to')),
        ];

        $base = WarehouseScope::apply(InboundHeader::query(), $request->user())
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id));

        $documents = (clone $base)
            ->withCount('details')
            // Hanya kolom batch_no yang diambil dari detail; memuat seluruh
            // kolom untuk ratusan palet hanya untuk menampilkan daftar batch
            // adalah pemborosan.
            ->with(['warehouse:id,code,name', 'creator:id,full_name', 'details:id,inbound_header_id,batch_no'])
            ->search($filters['search'])
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('production_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('production_date', '<=', $to))
            ->latest('production_date')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('wms.inbound.history', [
            'documents' => $documents,
            'warehouses' => WarehouseScope::options($request->user()),
            'statuses' => InboundHeader::STATUS_LABELS,
            'stats' => [
                'total' => (clone $base)->count(),
                'putaway' => (clone $base)->where('status', InboundHeader::STATUS_PUTAWAY_PENDING)->count(),
                'verifikasi' => (clone $base)->whereIn('status', [
                    InboundHeader::STATUS_VERIFICATION_PENDING,
                    InboundHeader::STATUS_PARTIAL_VERIFIED,
                ])->count(),
                'selesai' => (clone $base)->where('status', InboundHeader::STATUS_VERIFIED)->count(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * F-INB-01: Detail Riwayat Input Produksi.
     *
     * DATA CONTRACT (view: wms.inbound.history-detail)
     * ------------------------------------------------
     * $header          : InboundHeader — eager-load warehouse & creator
     * $details         : Collection<InboundDetail> — eager-load product,
     *                    location, penempat, & penyesuai qty
     * $berselisih      : Collection<InboundDetail> — palet yang qty fisiknya
     *                    berbeda dari yang ditulis Produksi
     * $bolehSesuaikan  : bool — ada selisih yang belum ditanggapi DAN dokumen
     *                    belum disahkan Logistik
     * $totals          : array{palet:int, qty:int, produk:int, batch:int}
     *
     * Dicari berdasarkan `document_number`, bukan id, agar URL-nya terbaca
     * manusia dan cocok dengan nomor yang tercetak di dokumen fisik.
     */
    public function historyDetail(Request $request, string $doc_no): View
    {
        $header = InboundHeader::with(['warehouse', 'creator'])
            ->where('document_number', $doc_no)
            ->firstOrFail();

        // Nomor dokumen terbaca manusia — dan karena itu mudah ditebak.
        // Menyaring daftarnya saja tidak menutup apa-apa.
        WarehouseScope::assert($header->warehouse_id, $request->user());

        $details = $header->details()
            ->with([
                'product:id,sku,name,uom,pack_unit,pack_size,max_qty_per_pallet',
                'location:id,code',
                'qtyAdjustedBy:id,full_name',
                'putawayBy:id,full_name',
            ])
            ->orderBy('production_order_no')
            ->orderBy('pallet_no')
            ->get();

        // Palet berselisih dipisahkan ke panelnya sendiri di atas tabel.
        // Menyerahkannya kepada mata pembaca — "cari sendiri baris mana yang
        // qty-nya beda" — adalah cara paling andal membuat selisih terlewat
        // pada dokumen berisi puluhan palet.
        $berselisih = $details->filter(fn (InboundDetail $d) => $d->qty_variance !== null && $d->qty_variance !== 0);

        return view('wms.inbound.history-detail', [
            'header' => $header,
            'details' => $details,
            'berselisih' => $berselisih->values(),
            // Tombol "Sesuaikan" hanya muncul kalau ada yang bisa disesuaikan
            // DAN dokumennya belum disahkan Logistik. Tombol yang selalu
            // terlihat lalu selalu ditolak melatih orang mengabaikan layar.
            'bolehSesuaikan' => $header->status !== InboundHeader::STATUS_VERIFIED
                && $berselisih->contains(fn (InboundDetail $d) => ! $d->sudah_disesuaikan),
            'totals' => [
                'palet' => $details->count(),
                // Qty dijumlahkan dari pallet_qty, BUKAN total_qty: total_qty
                // berulang pada tiap palet yang berasal dari satu baris
                // produksi, sehingga menjumlahkannya akan berlipat ganda.
                'qty' => $details->sum('pallet_qty'),
                'produk' => $details->pluck('product_id')->unique()->count(),
                'batch' => $details->pluck('batch_no')->unique()->count(),
            ],
        ]);
    }

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

    /**
     * F-INB-01: Tim Produksi menyesuaikan qty ke hasil hitung fisik Operator.
     *
     * KENAPA LAYAR INI ADA
     * --------------------
     * Operator boleh mengoreksi Qty Aktual saat PDN, dan selisihnya dipakai
     * sebagai angka stok. Tetapi Tim Produksi — yang mengetik angka aslinya —
     * tidak pernah diberi tahu. Dokumen mereka salah, barangnya sudah naik
     * rak, dan mereka baru tahu kalau kebetulan membuka riwayat lalu
     * membandingkan sendiri kolom demi kolom. Sekarang loncengnya berbunyi
     * (lihat kirimKabarSelisih) dan koreksinya bisa dilakukan di tempat.
     *
     * YANG TIDAK DILAKUKAN TOMBOL INI
     * -------------------------------
     * Ia TIDAK menghapus selisihnya dari layar verifikasi Logistik. Angka
     * semula pindah ke pallet_qty_original dan seluruh perhitungan selisih
     * membandingkan ke sana — lihat InboundDetail::scopeBerselisih(). Kalau
     * tidak begitu, Tim Produksi bisa merapikan sendiri bukti kesalahannya
     * sebelum orang yang bertugas memeriksanya sempat melihat.
     *
     * Ia juga TIDAK menyentuh stok. Stok memakai qty_actual sejak awal
     * (InboundDetail::effective_qty), jadi yang dibereskan di sini adalah
     * dokumennya, bukan isinya rak.
     *
     * SETELAH DIVERIFIKASI, PINTUNYA TERTUTUP
     * ---------------------------------------
     * Begitu Logistik mengesahkan, angka itu sudah menjadi stok yang hidup di
     * ledger. Mengubah dokumennya setelah itu membuat dokumen dan buku besar
     * bercerita berbeda tentang kejadian yang sama.
     */
    public function adjustQty(Request $request, string $doc_no): RedirectResponse
    {
        $header = InboundHeader::where('document_number', $doc_no)->firstOrFail();

        WarehouseScope::assert($header->warehouse_id, $request->user());

        if ($header->status === InboundHeader::STATUS_VERIFIED) {
            return back()->with('error',
                'Dokumen ini sudah diverifikasi Logistik dan stoknya sudah aktif. '
                .'Angkanya tidak bisa diubah lagi dari sini — koreksi selisih setelah verifikasi dilakukan lewat Koreksi Stok.');
        }

        $validated = $request->validate([
            'pallets' => ['required', 'array', 'min:1'],
            'pallets.*' => ['integer'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], [], [
            'pallets' => 'palet yang disesuaikan',
            'reason' => 'alasan penyesuaian',
        ]);

        // Hanya palet MILIK dokumen ini, yang memang berselisih, dan yang
        // belum pernah disesuaikan. Id palet datang dari formulir dan bisa
        // diganti angka apa pun lewat peramban.
        $terpilih = $header->details()
            ->whereIn('id', $validated['pallets'])
            ->selisihBelumDitanggapi()
            ->get();

        if ($terpilih->isEmpty()) {
            return back()->with('error', 'Tidak ada palet berselisih yang bisa disesuaikan dari pilihan itu.');
        }

        DB::transaction(function () use ($terpilih, $validated, $request, $header) {
            foreach ($terpilih as $detail) {
                $detail->update([
                    'pallet_qty_original' => $detail->pallet_qty,
                    'pallet_qty' => $detail->qty_actual,
                    'qty_adjusted_by' => $request->user()?->id,
                    'qty_adjusted_at' => now(),
                    'qty_adjust_reason' => $validated['reason'],
                ]);
            }

            // total_qty menyimpan jumlah SEBELUM dipecah menjadi palet, dan
            // dipakai layar detail untuk menampilkan "satu baris produksi
            // 235 pcs". Membiarkannya di angka lama membuat jumlah paletnya
            // tidak lagi berjumlah sama dengan barisnya — dan yang membaca
            // akan mengira ada palet yang hilang.
            foreach ($terpilih->groupBy(fn ($d) => $d->production_order_no.'|'.$d->batch_no.'|'.$d->product_id) as $grup) {
                $contoh = $grup->first();

                $baru = (int) $header->details()
                    ->where('production_order_no', $contoh->production_order_no)
                    ->where('batch_no', $contoh->batch_no)
                    ->where('product_id', $contoh->product_id)
                    ->sum('pallet_qty');

                $header->details()
                    ->where('production_order_no', $contoh->production_order_no)
                    ->where('batch_no', $contoh->batch_no)
                    ->where('product_id', $contoh->product_id)
                    ->update(['total_qty' => $baru, 'updated_at' => now()]);
            }
        });

        Activity::record(
            ActivityLog::INBOUND_QTY_ADJUST,
            sprintf(
                'Menyesuaikan qty dokumen %s ke hitungan fisik — %d palet. Alasan: %s',
                $header->document_number,
                $terpilih->count(),
                $validated['reason'],
            ),
            $header,
            $header->warehouse_id,
            [
                'dokumen' => $header->document_number,
                'palet' => $terpilih->count(),
                'alasan' => $validated['reason'],
                'perubahan' => $terpilih->map(fn (InboundDetail $d) => [
                    'palet' => $d->pallet_no,
                    'batch' => $d->batch_no,
                    'semula' => $d->pallet_qty,
                    'menjadi' => $d->qty_actual,
                ])->all(),
            ],
        );

        return back()->with('success', sprintf(
            '%d palet disesuaikan ke hitungan fisik. Angka semula tetap tercatat dan selisihnya tetap '
                .'terlihat oleh Logistik saat verifikasi — penyesuaian ini menambah keterangan, bukan menghapus temuan.',
            $terpilih->count(),
        ));
    }

    /**
     * Memberi tahu Tim Produksi bahwa hitungan fisiknya berbeda.
     *
     * Dikirim ke pemegang izin INBOUND_CREATE di gudang itu — Tim Produksi
     * dan Super Admin. Pembuat dokumennya diberi tahu terpisah kalau ternyata
     * ia tidak tercakup: dialah yang mengetik angkanya, dan dia yang paling
     * perlu tahu berkas buatannya meleset.
     *
     * Notifier tidak pernah mengirim ke diri sendiri, jadi Operator yang baru
     * saja menekan simpan tidak menerima loncengnya sendiri.
     */
    private function kirimKabarSelisih(InboundHeader $header): void
    {
        $berselisih = $header->details()->berselisih()->get();

        if ($berselisih->isEmpty()) {
            return;
        }

        $judul = 'Qty fisik berbeda dari dokumen produksi';
        $isi = sprintf(
            'Dokumen %s — %d palet dihitung ulang Operator dan hasilnya berbeda (%s). '
                .'Buka detailnya untuk menyesuaikan angka dokumen.',
            $header->document_number,
            $berselisih->count(),
            $berselisih
                ->take(3)
                ->map(fn (InboundDetail $d) => sprintf(
                    'batch %s: %d → %d',
                    $d->batch_no,
                    $d->qty_sistem_asli,
                    $d->qty_actual,
                ))
                ->implode('; ').($berselisih->count() > 3 ? '; …' : ''),
        );

        $url = route('wms.inbound.history.detail', $header->document_number);

        Notifier::toPermission(
            Permission::INBOUND_CREATE,
            $header->warehouse_id,
            Notification::INBOUND_QTY_VARIANCE,
            $judul,
            $isi,
            $url,
            $header,
        );

        // Pembuat dokumen bisa saja TIDAK tercakup kiriman di atas: perannya
        // berubah, akunnya dipindah gudang, atau ia dinonaktifkan lalu aktif
        // lagi. Dia yang mengetik angkanya, jadi dia yang paling perlu tahu.
        //
        // Diperiksa dulu apakah ia sudah termasuk — Notifier menulis apa yang
        // diberikan tanpa memeriksa penerima ganda, jadi memanggil keduanya
        // begitu saja akan membunyikan dua lonceng identik untuk satu orang.
        $pembuat = $header->creator()->first();

        $sudahDapat = $pembuat !== null
            && $pembuat->is_active
            && Permission::allows($pembuat, Permission::INBOUND_CREATE)
            && ($pembuat->warehouse_id === null || $pembuat->warehouse_id === $header->warehouse_id);

        if (! $sudahDapat) {
            Notifier::toUser(
                $header->created_by,
                Notification::INBOUND_QTY_VARIANCE,
                $judul,
                $isi,
                $url,
                $header->warehouse_id,
                $header,
            );
        }
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

    /**
     * F-INB-02: Daftar dokumen yang menunggu put-away.
     *
     * DATA CONTRACT (view: wms.inbound.putaway-list)
     * ----------------------------------------------
     * $documents  : LengthAwarePaginator<InboundHeader> — withCount details
     *               (total) & details_placed (sudah punya lokasi)
     * $warehouses : Collection<Warehouse>
     * $stats      : array{dokumen:int, palet:int, belum:int}
     * $filters    : array{search:?string, warehouse_id:?string}
     *
     * Hanya dokumen berstatus `putaway_pending` yang muncul. Dokumen yang
     * put-away-nya baru sebagian tetap di daftar ini — lihat kolom kemajuan —
     * karena pekerjaan fisik lazim terputus dan harus bisa dilanjutkan.
     */
    public function putawayIndex(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'warehouse_id' => WarehouseScope::resolveFilter($request, $request->user()),
        ];

        $base = WarehouseScope::apply(InboundHeader::query(), $request->user())
            ->awaitingPutaway()
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id));

        $documents = (clone $base)
            ->withCount(['details', 'details as details_placed_count' => fn ($q) => $q->placed()])
            ->with(['warehouse:id,code,name', 'details:id,inbound_header_id,batch_no'])
            ->search($filters['search'])
            // Dokumen terlama didahulukan: barang yang sudah lama menganggur di
            // area terima adalah yang paling mendesak dimasukkan ke rak.
            ->oldest('production_date')
            ->oldest('id')
            ->paginate(15)
            ->withQueryString();

        $paletBase = InboundDetail::whereIn('inbound_header_id', (clone $base)->select('id'));

        return view('wms.inbound.putaway-list', [
            'documents' => $documents,
            'warehouses' => WarehouseScope::options($request->user()),
            'stats' => [
                'dokumen' => (clone $base)->count(),
                'palet' => (clone $paletBase)->count(),
                'belum' => (clone $paletBase)->whereNull('location_id')->count(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * F-INB-02: Layar penempatan palet ke rak.
     *
     * DATA CONTRACT (view: wms.inbound.putaway-process)
     * -------------------------------------------------
     * $header    : InboundHeader
     * $details   : Collection<InboundDetail> — eager-load product & location
     * $locations : Collection<Location> — SELURUH bin aktif di gudang dokumen
     *              ini; ketersediaan per baris dihitung di sisi klien karena
     *              tergantung SKU baris itu (lihat $occupancy)
     * $occupancy : array<string, array{product_id:int, qty:int, capacity:?int,
     *              uom:?string}> — kode bin => isi bin saat ini
     * $totals    : array{palet:int, ditempatkan:int}
     *
     * Daftar bin dibatasi ke gudang dokumen. Tanpa itu, Operator bisa memilih
     * bin milik gudang lain — kode rak seperti "B-01-01" berulang antar gudang,
     * jadi kesalahannya tidak akan terlihat sampai barangnya dicari.
     */
    public function putawayProcess(Request $request, string $doc_no): View
    {
        $header = InboundHeader::with('warehouse')
            ->awaitingPutaway()
            ->where('document_number', $doc_no)
            ->firstOrFail();

        WarehouseScope::assert($header->warehouse_id, $request->user());

        $details = $header->details()
            ->with(['product:id,sku,name,uom,pack_unit,pack_size,max_qty_per_pallet', 'location:id,code'])
            ->orderBy('production_order_no')
            ->orderBy('pallet_no')
            ->get();

        $locations = Location::where('warehouse_id', $header->warehouse_id)
            ->active()
            ->penyimpanan()
            ->inStorageOrder()
            ->get(['id', 'code', 'zone']);

        return view('wms.inbound.putaway-process', [
            'header' => $header,
            'details' => $details,
            'locations' => $locations,
            'occupancy' => BinAllocator::occupancyByCode($locations),
            'totals' => [
                'palet' => $details->count(),
                'ditempatkan' => $details->whereNotNull('location_id')->count(),
            ],
        ]);
    }

    /**
     * F-INB-02: menyimpan penempatan palet.
     *
     * Aturan yang membentuk method ini:
     *
     * 1. PUT-AWAY BOLEH SEBAGIAN. Palet yang lokasinya dikosongkan hanya
     *    dilewati, tidak menggagalkan penyimpanan. Memaksa semua palet terisi
     *    sekaligus akan membuat Operator kehilangan pekerjaan setengah jalan
     *    setiap kali giliran kerjanya habis.
     * 2. STATUS NAIK HANYA BILA LENGKAP. Dokumen baru berpindah ke
     *    `verification_pending` setelah seluruh paletnya punya lokasi.
     * 3. SATU BIN = SATU SLOT PALET. Boleh memuat beberapa palet dari SKU
     *    yang SAMA sampai kapasitas palet SKU itu (Product::max_qty_per_
     *    pallet) — pallet split (PRD §7.1) boleh digabung kembali di bin
     *    yang sama. SKU yang berbeda TIDAK boleh berbagi bin.
     *
     * Qty Aktual boleh dikoreksi Operator (PRD §6.3 F-INB-02) — SKU dan batch
     * tidak, karena keduanya berasal dari dokumen produksi dan bukan wewenang
     * gudang untuk mengubahnya.
     */
    public function putawayStore(Request $request, string $doc_no): RedirectResponse
    {
        $header = InboundHeader::awaitingPutaway()
            ->where('document_number', $doc_no)
            ->firstOrFail();

        WarehouseScope::assert($header->warehouse_id, $request->user());

        $validated = $request->validate([
            'pallets' => ['required', 'array'],
            'pallets.*.location_code' => ['nullable', 'string', 'max:20'],
            // Batas atas 100.000 mencegah salah ketik yang mustahil secara
            // fisik; palet terbesar di sistem ini memuat 720 pcs.
            'pallets.*.qty_actual' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $details = $header->details()->with('product:id,uom,pack_unit,pack_size,max_qty_per_pallet')->get()->keyBy('id');
        $allocator = BinAllocator::forWarehouse($header->warehouse_id, $header->warehouse?->code);

        $errors = [];

        // TAHAP 1 — kumpulkan kandidat & periksa isian dasarnya. Belum
        // menyentuh aturan kapasitas: seluruh kandidat harus diketahui lebih
        // dulu supaya bisa dilepas bersama-sama pada tahap 2.
        $kandidat = [];

        foreach ($validated['pallets'] as $detailId => $input) {
            $detail = $details->get((int) $detailId);

            // Palet dari dokumen lain diabaikan diam-diam: id-nya bisa saja
            // dikarang lewat peramban, dan tidak ada alasan sah untuk itu.
            if (! $detail) {
                continue;
            }

            $code = $allocator->normalize((string) ($input['location_code'] ?? ''));

            if ($code === '') {
                continue;
            }

            if (! $allocator->has($code)) {
                $errors["pallets.{$detailId}.location_code"] = $allocator->unknownCodeMessage($code);

                continue;
            }

            $qty = $input['qty_actual'] ?? null;

            if ($qty === null || $qty === '') {
                $errors["pallets.{$detailId}.qty_actual"] = 'Qty Aktual wajib diisi untuk palet yang ditempatkan.';

                continue;
            }

            $kandidat[$detail->id] = ['detail' => $detail, 'code' => $code, 'qty' => (int) $qty];
        }

        // TAHAP 2 — lepas seluruh kandidat dari isi bin lama, lalu tempatkan.
        // Tanpa pelepasan ini, palet yang disimpan ulang terhitung dua kali.
        $allocator->release(array_keys($kandidat));

        $penempatan = [];

        foreach ($kandidat as $detailId => $calon) {
            $hasil = $allocator->place($calon['detail'], $calon['code'], $calon['qty']);

            if (isset($hasil['error'])) {
                $errors["pallets.{$detailId}.location_code"] = $hasil['error'];

                continue;
            }

            $penempatan[$detailId] = [
                'location_id' => $hasil['location_id'],
                'qty_actual' => $calon['qty'],
            ];
        }

        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        if ($penempatan === []) {
            return back()->with('error', 'Belum ada palet yang diberi lokasi rak.');
        }

        DB::transaction(function () use ($header, $penempatan, $request) {
            foreach ($penempatan as $detailId => $nilai) {
                $header->details()->whereKey($detailId)->update($nilai + [
                    'putaway_by' => $request->user()?->id,
                    'putaway_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($header->isFullyPlaced()) {
                $header->update(['status' => InboundHeader::STATUS_VERIFICATION_PENDING]);
            }
        });

        $header->refresh();
        $tersisa = $header->details()->whereNull('location_id')->count();

        if ($tersisa > 0) {
            return redirect()->route('wms.inbound.putaway.process', $header->document_number)->with(
                'success',
                sprintf(
                    '%d palet tersimpan. Masih ada %d palet yang belum ditempatkan — dokumen tetap di daftar PDN.',
                    count($penempatan),
                    $tersisa
                )
            );
        }

        Activity::record(
            ActivityLog::INBOUND_PUTAWAY,
            sprintf(
                'Menaikkan dokumen %s ke rak — %d palet ditempatkan.',
                $header->document_number,
                $header->details()->count(),
            ),
            $header,
            $header->warehouse_id,
            ['dokumen' => $header->document_number, 'palet' => $header->details()->count()],
        );

        Notifier::toPermission(
            Permission::INBOUND_VERIFY,
            $header->warehouse_id,
            Notification::INBOUND_VERIFY_READY,
            'Barang masuk menunggu verifikasi',
            sprintf(
                'Dokumen %s sudah naik rak — %d palet menunggu diperiksa. Stok belum aktif sebelum diverifikasi.',
                $header->document_number,
                $header->details()->count(),
            ),
            route('wms.inbound.verify'),
            $header,
        );

        // Tim Produksi diberi tahu kalau hitungan fisik Operator berbeda dari
        // angka yang mereka tulis. Sebelum ini tidak ada satu pun jalur yang
        // memberitahu mereka — selisihnya hanya beredar antara Operator dan
        // Logistik, padahal yang bisa memperbaiki sumbernya adalah Produksi.
        $this->kirimKabarSelisih($header);

        return redirect()->route('wms.inbound.putaway')->with('success', sprintf(
            'PDN dokumen %s selesai: %d palet ditempatkan, kini menunggu verifikasi Logistik.',
            $header->document_number,
            $header->details()->count()
        ));
    }

    /**
     * F-INB-03: Daftar dokumen yang menunggu verifikasi Logistik.
     *
     * DATA CONTRACT (view: wms.inbound.verify-list)
     * ---------------------------------------------
     * $documents  : LengthAwarePaginator<InboundHeader> — withCount details
     *               (total) & details_verified_count
     * $warehouses : Collection<Warehouse>
     * $stats      : array{dokumen:int, palet:int, belum:int, selisih:int}
     * $filters    : array{search:?string, warehouse_id:?string}
     *
     * `selisih` menghitung palet yang qty fisiknya BERBEDA dari qty sistem —
     * itulah yang paling perlu perhatian Logistik, karena di situlah angka
     * final stok diputuskan (PRD §6.3 catatan Maker-Checker).
     */
    public function verifyIndex(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'warehouse_id' => WarehouseScope::resolveFilter($request, $request->user()),
        ];

        $base = WarehouseScope::apply(InboundHeader::query(), $request->user())
            ->awaitingVerification()
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id));

        $documents = (clone $base)
            ->withCount([
                'details',
                'details as details_verified_count' => fn ($q) => $q->where('is_verified', true),
            ])
            ->with(['warehouse:id,code,name', 'details:id,inbound_header_id,batch_no'])
            ->search($filters['search'])
            // Dokumen terlama didahulukan: barang yang sudah lama menunggu
            // verifikasi adalah stok yang belum bisa dijual sama sekali.
            ->oldest('production_date')
            ->oldest('id')
            ->paginate(15)
            ->withQueryString();

        $paletBase = InboundDetail::whereIn('inbound_header_id', (clone $base)->select('id'));

        return view('wms.inbound.verify-list', [
            'documents' => $documents,
            'warehouses' => WarehouseScope::options($request->user()),
            'stats' => [
                'dokumen' => (clone $base)->count(),
                'palet' => (clone $paletBase)->count(),
                'belum' => (clone $paletBase)->where('is_verified', false)->count(),
                // berselisih() membandingkan ke angka SEMULA, bukan ke
                // pallet_qty — penyesuaian Tim Produksi tidak boleh membuat
                // palet ini menghilang dari perhatian Logistik.
                'selisih' => (clone $paletBase)
                    ->where('is_verified', false)
                    ->berselisih()
                    ->count(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * F-INB-03: Layar verifikasi fisik oleh Logistik.
     *
     * DATA CONTRACT (view: wms.inbound.verify-process)
     * ------------------------------------------------
     * $header    : InboundHeader
     * $details   : Collection<InboundDetail> — eager-load product, location,
     *              putawayBy, verifiedBy
     * $locations : Collection<Location> — bin aktif di gudang dokumen ini
     * $occupancy : array<string, array{...}> — isi tiap bin, format sama
     *              dengan layar put-away
     * $totals    : array{palet:int, terverifikasi:int, selisih:int}
     *
     * Logistik boleh mengoreksi Qty dan Lokasi (PRD §6.3 F-INB-03 langkah 8),
     * TAPI TIDAK batch/SKU — lihat catatan panjang di verifyStore().
     */
    public function verifyProcess(Request $request, string $doc_no): View
    {
        $header = InboundHeader::with('warehouse')
            ->awaitingVerification()
            ->where('document_number', $doc_no)
            ->firstOrFail();

        WarehouseScope::assert($header->warehouse_id, $request->user());

        $details = $header->details()
            ->with([
                'product:id,sku,name,uom,pack_unit,pack_size,max_qty_per_pallet',
                'location:id,code',
                'putawayBy:id,full_name',
                'verifiedBy:id,full_name',
            ])
            ->orderBy('production_order_no')
            ->orderBy('pallet_no')
            ->get();

        $locations = Location::where('warehouse_id', $header->warehouse_id)
            ->active()
            ->penyimpanan()
            ->inStorageOrder()
            ->get(['id', 'code', 'zone']);

        return view('wms.inbound.verify-process', [
            'header' => $header,
            'details' => $details,
            'locations' => $locations,
            'occupancy' => BinAllocator::occupancyByCode($locations),
            'totals' => [
                'palet' => $details->count(),
                'terverifikasi' => $details->where('is_verified', true)->count(),
                'selisih' => $details->filter(fn (InboundDetail $d) => $d->qty_variance !== null && $d->qty_variance !== 0)->count(),
            ],
        ]);
    }

    /**
     * F-INB-03: menyimpan hasil verifikasi Logistik.
     *
     * Aturan yang membentuk method ini:
     *
     * 1. VERIFIKASI BOLEH SEBAGIAN (PRD §6.3 F-INB-03 langkah 8: Logistik
     *    boleh MENUNDA). Palet yang belum dicentang tidak menggagalkan
     *    penyimpanan palet yang sudah; dokumen turun ke `partial_verified`
     *    dan tetap muncul di daftar sampai seluruh paletnya selesai.
     * 2. VERIFIKASI TIDAK BISA DIBATALKAN LEWAT LAYAR INI. Palet yang sudah
     *    `is_verified` diabaikan dari perubahan apa pun — PRD §6.3 F-INB-04
     *    menegaskan koreksi pasca-verifikasi HANYA lewat Menu Stok oleh
     *    Manager/Super Admin, karena begitu terverifikasi angkanya sudah
     *    menjadi stok resmi yang mungkin sudah ikut teralokasi ke order.
     * 3. QTY & LOKASI boleh dikoreksi, BATCH & SKU TIDAK. PRD langkah 8
     *    menyebut "qty, lokasi, batch", tapi batch adalah nomor QC yang
     *    menjadi jejak telusur balik ke dokumen produksi — mengubahnya di
     *    gudang memutus rantai itu tanpa jejak. Dikunci mengikuti rancangan
     *    layar (mock) dan konsisten dengan put-away; lihat catatan Fase 3c
     *    di rencana pembangunan.
     * 4. Perpindahan lokasi tetap tunduk aturan kapasitas bin yang SAMA
     *    dengan put-away — lewat App\Support\Inbound\BinAllocator.
     *
     * 5. STOK RESMI AKTIF di sini (PRD langkah 9-10): tiap palet yang
     *    diverifikasi menghasilkan baris `inventory_stocks` + entri `IN` di
     *    `stock_movements`, lewat App\Support\Inventory\StockActivator.
     *    Keduanya berada di dalam transaksi yang sama dengan penandaan
     *    paletnya — stok aktif tanpa palet terverifikasi (atau sebaliknya)
     *    adalah keadaan yang tidak bisa dibetulkan sendiri oleh sistem.
     */
    public function verifyStore(Request $request, string $doc_no): RedirectResponse
    {
        $header = InboundHeader::with('warehouse')
            ->awaitingVerification()
            ->where('document_number', $doc_no)
            ->firstOrFail();

        WarehouseScope::assert($header->warehouse_id, $request->user());

        $validated = $request->validate([
            'pallets' => ['required', 'array'],
            'pallets.*.verified' => ['nullable', 'boolean'],
            'pallets.*.location_code' => ['nullable', 'string', 'max:20'],
            'pallets.*.qty_actual' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $details = $header->details()->with('product:id,uom,pack_unit,pack_size,max_qty_per_pallet')->get()->keyBy('id');
        $allocator = BinAllocator::forWarehouse($header->warehouse_id, $header->warehouse?->code);

        $errors = [];

        // TAHAP 1 — kumpulkan kandidat & periksa isian dasarnya. Aturan
        // kapasitas belum disentuh: seluruh kandidat harus diketahui lebih
        // dulu supaya bisa dilepas bersama-sama pada tahap 2.
        $kandidat = [];

        foreach ($validated['pallets'] as $detailId => $input) {
            $detail = $details->get((int) $detailId);

            // Palet dari dokumen lain diabaikan diam-diam: id-nya bisa saja
            // dikarang lewat peramban, dan tidak ada alasan sah untuk itu.
            if (! $detail) {
                continue;
            }

            // Sudah terverifikasi -> terkunci (aturan 2 di atas).
            if ($detail->is_verified) {
                continue;
            }

            if (! filter_var($input['verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $code = $allocator->normalize((string) ($input['location_code'] ?? ''));

            if ($code === '') {
                $errors["pallets.{$detailId}.location_code"] = 'Lokasi rak wajib diisi untuk palet yang diverifikasi.';

                continue;
            }

            if (! $allocator->has($code)) {
                $errors["pallets.{$detailId}.location_code"] = $allocator->unknownCodeMessage($code);

                continue;
            }

            $qty = $input['qty_actual'] ?? null;

            if ($qty === null || $qty === '') {
                $errors["pallets.{$detailId}.qty_actual"] = 'Qty wajib diisi untuk palet yang diverifikasi.';

                continue;
            }

            $kandidat[$detail->id] = ['detail' => $detail, 'code' => $code, 'qty' => (int) $qty];
        }

        // TAHAP 2 — palet yang diverifikasi SUDAH menghuni bin sejak put-away;
        // lepas dulu supaya jumlahnya tidak terhitung dua kali (dari database
        // dan dari kiriman formulir).
        $allocator->release(array_keys($kandidat));

        $perubahan = [];

        foreach ($kandidat as $detailId => $calon) {
            $hasil = $allocator->place($calon['detail'], $calon['code'], $calon['qty']);

            if (isset($hasil['error'])) {
                $errors["pallets.{$detailId}.location_code"] = $hasil['error'];

                continue;
            }

            $perubahan[$detailId] = [
                'location_id' => $hasil['location_id'],
                'qty_actual' => $calon['qty'],
                'is_verified' => true,
            ];
        }

        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        if ($perubahan === []) {
            return back()->with('error', 'Belum ada palet yang dicentang untuk diverifikasi.');
        }

        $susulan = DB::transaction(function () use ($header, $perubahan, $request) {
            $activator = new StockActivator;
            $userId = $request->user()?->id;
            $produkTersentuh = [];

            foreach ($perubahan as $detailId => $nilai) {
                $header->details()->whereKey($detailId)->update($nilai + [
                    'verified_by' => $userId,
                    'verified_at' => now(),
                    'updated_at' => now(),
                ]);

                // Stok RESMI AKTIF di sini (PRD §6.3 F-INB-03 langkah 9-10).
                // Dibaca ulang dari basis data supaya memakai qty & lokasi
                // yang baru saja disimpan, bukan nilai model yang basi.
                $detail = $header->details()->with(['product:id,shelf_life_months', 'header'])->findOrFail($detailId);
                $activator->activate($detail, $userId);

                $produkTersentuh[$detail->product_id] = true;
            }

            $header->update(['status' => $header->resolveVerificationStatus()]);

            /*
             * JANJI YANG SUDAH ADA DILAYANI DI SINI — bukan menunggu ada yang
             * ingat. Inilah jalur yang paling sering dilewati barang di Berger
             * (produksi -> cek operator -> naik rak), dan dulu justru
             * satu-satunya jalur masuk stok yang TIDAK melayani janji yang
             * sudah menumpuk. Akibatnya barang mendarat dalam keadaan bebas,
             * lalu pesanan lain yang kebetulan diproses lebih dulu
             * menyambarnya lewat FIFO — sementara jatah yang sudah dijanjikan
             * berminggu-minggu sebelumnya hilang tanpa ada yang sadar.
             */
            $hasil = ['terisi' => 0, 'pesanan' => [], 'booking' => []];

            foreach (array_keys($produkTersentuh) as $productId) {
                $bagian = $this->pengisi->fill($productId, $header->warehouse_id, $userId);

                $hasil['terisi'] += $bagian['terisi'];
                $hasil['pesanan'] = array_merge($hasil['pesanan'], $bagian['pesanan']);
                $hasil['booking'] = array_merge($hasil['booking'], $bagian['booking']);
            }

            return $hasil;
        });

        $header->refresh();
        $tersisa = $header->details()->where('is_verified', false)->count();

        // DILAPORKAN, bukan dikerjakan diam-diam. Barang baru yang sebagian
        // langsung punya pemilik adalah hal pertama yang perlu diketahui
        // operator: kalau tidak, ia melihat 10 unit naik rak lalu heran
        // kenapa yang bisa dijual cuma 5.
        $catatan = $this->pengisi->ringkasan($susulan);

        /*
         * Dicatat SEKALI PER PENEKANAN, bukan per palet. Verifikasi bisa
         * dicicil, dan satu baris log per palet akan menenggelamkan seluruh
         * log hari itu oleh satu dokumen berisi ratusan palet.
         *
         * Yang berselisih disebut terpisah: di situlah angka stok final
         * diputuskan, dan itu justru bagian yang paling perlu bisa
         * ditelusuri kembali.
         */
        Activity::record(
            ActivityLog::INBOUND_VERIFY,
            sprintf(
                'Verifikasi dokumen %s — %d palet disahkan, %d belum.',
                $header->document_number,
                count($perubahan),
                $tersisa,
            ),
            $header,
            $header->warehouse_id,
            [
                'dokumen' => $header->document_number,
                'disahkan' => count($perubahan),
                'tersisa' => $tersisa,
                // Dibaca dari baris yang barusan disahkan, bukan dari
                // $perubahan — larik itu tidak memuat pallet_qty, jadi
                // membandingkannya di sana akan menghitung SEMUANYA sebagai
                // selisih tanpa ada yang menyadarinya.
                'berselisih' => InboundDetail::query()
                    ->whereIn('id', array_keys($perubahan))
                    ->berselisih()
                    ->count(),
            ],
        );

        if ($tersisa > 0) {
            return redirect()->route('wms.inbound.verify.process', $header->document_number)->with(
                'success',
                sprintf(
                    '%d palet terverifikasi. Masih ada %d palet yang belum diverifikasi — dokumen tetap di daftar verifikasi.',
                    count($perubahan),
                    $tersisa
                ).($catatan ? ' '.$catatan : '')
            );
        }

        return redirect()->route('wms.inbound.verify')->with('success', sprintf(
            'Verifikasi dokumen %s selesai: %d palet terverifikasi dan stoknya kini aktif.',
            $header->document_number,
            $header->details()->count()
        ).($catatan ? ' '.$catatan : ''));
    }
}
