<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PalletCapacityRule;
use App\Models\Product;
use App\Support\Activity;
use App\Support\PalletCapacity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kapasitas Palet — berapa banyak muat di satu palet, menurut ukurannya.
 *
 * KENAPA INI SETELAN, BUKAN ANGKA DI KODE
 * ---------------------------------------
 * Permintaan pemilik produk: ukuran baru datang terus, dan angkanya
 * sewaktu-waktu berubah. Sebelum layar ini, aturannya tertanam di
 * App\Support\PalletCapacity sebagai array PHP — ukuran baru berarti menunggu
 * rilis kode, dan 316 produk yang ukurannya tidak tercakup berdiri tanpa
 * kapasitas palet sama sekali, yang berarti pemecahan palet otomatis
 * (PRD §7.1) tidak jalan untuk mereka.
 *
 * SATU ANGKA MENUTUP SATU UKURAN, BUKAN SATU PRODUK. Itu inti gunanya: "20 L
 * PAIL = 36" langsung berlaku untuk seluruh 204 produk berukuran itu, termasuk
 * produk yang belum dibuat. Mengisinya satu per satu di Master Produk
 * mengerjakan pekerjaan yang sama dua ratus kali.
 *
 * MENGHAPUS ATURAN ITU TINDAKAN BESAR, dan karena itu jumlah produk yang
 * terdampak disebutkan lebih dulu — bukan setelah terjadi. Produk yang
 * kehilangan aturannya berhenti bisa dipecah jadi palet, dan gejalanya baru
 * muncul di layar penerimaan barang berikutnya.
 *
 * DATA CONTRACT
 * -------------
 * index() : $aturan Collection<PalletCapacityRule> (+ terdampak:int),
 *           $belum Collection<object{pack_unit,pack_size,uom,jumlah}>,
 *           $tanpaUkuran int, $units list<string>
 */
class PalletCapacityController extends Controller
{
    public function index(): View
    {
        $aturan = PalletCapacityRule::query()
            ->with('updatedBy:id,full_name')
            ->orderBy('pack_unit')
            ->orderBy('pack_size')
            ->orderByRaw('uom NULLS FIRST')
            ->get()
            ->each(fn (PalletCapacityRule $r) => $r->terdampak = $this->hitungTerdampak($r));

        return view('wms.admin.pallet-capacity', [
            'aturan' => $aturan,
            'belum' => $this->ukuranBelumPunyaAturan(),
            'tanpaUkuran' => $this->tanpaUkuranKemasan(),
            'units' => PalletCapacity::UNITS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validasi($request);

        if (PalletCapacityRule::query()
            ->where('pack_unit', $data['pack_unit'])
            ->where('pack_size', $data['pack_size'])
            ->when($data['uom'] === null,
                fn ($q) => $q->whereNull('uom'),
                fn ($q) => $q->where('uom', $data['uom']))
            ->exists()
        ) {
            return back()->withInput()->with('error', sprintf(
                'Aturan untuk %s %s %s sudah ada. Ubah yang sudah ada, jangan buat kembarannya — '.
                'dua aturan untuk ukuran yang sama membuat kapasitasnya bergantung pada baris mana yang kebetulan terbaca lebih dulu.',
                rtrim(rtrim(number_format((float) $data['pack_size'], 3, '.', ''), '0'), '.'),
                $data['pack_unit'],
                $data['uom'] ?? '(semua wadah)',
            ));
        }

        $aturan = PalletCapacityRule::create($data + ['updated_by' => $request->user()?->id]);

        PalletCapacity::lupakan();

        Activity::record(
            ActivityLog::SETTINGS_UPDATE,
            sprintf(
                'Menambah aturan kapasitas palet %s: %d per palet.',
                $aturan->sebutan,
                $aturan->max_qty_per_pallet,
            ),
            $aturan,
            null,
            $data,
        );

        return redirect()->route('wms.admin.pallet-capacity')->with('success', sprintf(
            'Aturan %s = %d per palet ditambahkan, dan langsung berlaku untuk %d produk.',
            $aturan->sebutan,
            $aturan->max_qty_per_pallet,
            $this->hitungTerdampak($aturan),
        ));
    }

    public function update(Request $request, PalletCapacityRule $rule): RedirectResponse
    {
        $data = $request->validate([
            'max_qty_per_pallet' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [], [
            'max_qty_per_pallet' => 'maksimal per palet',
            'note' => 'keterangan',
        ]);

        $lama = (int) $rule->max_qty_per_pallet;
        $baru = (int) $data['max_qty_per_pallet'];

        // Ukuran dan wadahnya SENGAJA tidak bisa diubah di sini. Mengubahnya
        // berarti aturan ini diam-diam berpindah menutupi kelompok produk yang
        // berbeda, sementara kelompok lamanya kehilangan aturannya tanpa ada
        // yang menyadarinya. Yang benar: hapus, lalu buat yang baru.
        $rule->update($data + ['updated_by' => $request->user()?->id]);

        PalletCapacity::lupakan();

        if ($lama === $baru) {
            return redirect()->route('wms.admin.pallet-capacity')
                ->with('success', 'Keterangan diperbarui; angkanya tidak berubah.');
        }

        Activity::record(
            ActivityLog::SETTINGS_UPDATE,
            sprintf(
                'Mengubah kapasitas palet %s: %d → %d per palet.',
                $rule->sebutan,
                $lama,
                $baru,
            ),
            $rule,
            null,
            ['lama' => $lama, 'baru' => $baru],
        );

        return redirect()->route('wms.admin.pallet-capacity')->with('success', sprintf(
            'Kapasitas %s diubah dari %d jadi %d per palet — berlaku untuk %d produk, mulai palet yang dibentuk sesudah ini. '.
            'Palet yang sudah terlanjur dibentuk TIDAK ikut berubah.',
            $rule->sebutan,
            $lama,
            $baru,
            $this->hitungTerdampak($rule),
        ));
    }

    public function destroy(Request $request, PalletCapacityRule $rule): RedirectResponse
    {
        $terdampak = $this->hitungTerdampak($rule);
        $sebutan = $rule->sebutan;
        $angka = (int) $rule->max_qty_per_pallet;

        $rule->delete();

        PalletCapacity::lupakan();

        Activity::record(
            ActivityLog::SETTINGS_UPDATE,
            sprintf('Menghapus aturan kapasitas palet %s (%d per palet).', $sebutan, $angka),
            null,
            null,
            ['aturan' => $sebutan, 'kapasitas' => $angka, 'produk_terdampak' => $terdampak],
        );

        // Dinyatakan sebagai PERINGATAN, bukan pesan sukses biasa: produk yang
        // kehilangan aturannya berhenti bisa dipecah jadi palet, dan gejalanya
        // baru muncul di layar penerimaan barang berikutnya.
        if ($terdampak > 0) {
            return redirect()->route('wms.admin.pallet-capacity')->with('warning', sprintf(
                'Aturan %s dihapus. %d produk sekarang TIDAK punya kapasitas palet dan tidak bisa dipecah jadi palet '.
                'sampai ada aturan penggantinya atau angkanya diisi manual di Master Produk.',
                $sebutan,
                $terdampak,
            ));
        }

        return redirect()->route('wms.admin.pallet-capacity')
            ->with('success', sprintf('Aturan %s dihapus. Tidak ada produk yang memakainya.', $sebutan));
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * @return array{pack_unit:string, pack_size:string, uom:?string, max_qty_per_pallet:int, note:?string}
     */
    private function validasi(Request $request): array
    {
        // DINORMALKAN SEBELUM DIVALIDASI, bukan sesudah. Daftar satuan yang
        // sah peka huruf besar-kecil, jadi "kg" yang diketik tangan ditolak
        // dengan pesan "harus L atau KG" — yang terbaca seperti sistem yang
        // tidak bisa membaca apa yang jelas-jelas benar.
        $request->merge([
            'pack_unit' => mb_strtoupper(trim((string) $request->input('pack_unit'))),
        ]);

        $data = $request->validate([
            'pack_unit' => ['required', Rule::in(PalletCapacity::UNITS)],
            'pack_size' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'uom' => ['nullable', 'string', 'max:20'],
            'max_qty_per_pallet' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [
            'pack_unit.in' => 'Satuan kemasan harus L (liter) atau KG.',
            'pack_size.gt' => 'Ukuran kemasan harus lebih dari nol.',
        ], [
            'pack_unit' => 'satuan kemasan',
            'pack_size' => 'ukuran kemasan',
            'uom' => 'wadah',
            'max_qty_per_pallet' => 'maksimal per palet',
        ]);

        // Wadah disimpan HURUF BESAR supaya "pail" dan "PAIL" tidak jadi dua
        // aturan yang berbeda untuk barang yang sama.
        $uom = trim((string) ($data['uom'] ?? ''));

        return [
            'pack_unit' => mb_strtoupper($data['pack_unit']),
            'pack_size' => PalletCapacity::kunciUkuran($data['pack_size']),
            'uom' => $uom === '' ? null : mb_strtoupper($uom),
            'max_qty_per_pallet' => (int) $data['max_qty_per_pallet'],
            'note' => $data['note'] ?? null,
        ];
    }

    /**
     * Berapa produk yang BENAR-BENAR memakai aturan ini.
     *
     * Yang punya angka khusus di Master Produk tidak dihitung — aturan ini
     * tidak menyentuh mereka. Begitu pula yang sudah tertutup aturan lain yang
     * lebih khusus (menyebut wadah), karena aturan itu yang menang.
     */
    private function hitungTerdampak(PalletCapacityRule $rule): int
    {
        $q = Product::query()
            ->whereNull('max_qty_per_pallet')
            ->where('pack_size', $rule->pack_size)
            ->whereRaw('UPPER(TRIM(pack_unit)) = ?', [$rule->pack_unit]);

        if ($rule->uom !== null) {
            return $q->whereRaw('UPPER(TRIM(uom)) = ?', [$rule->uom])->count();
        }

        // Aturan umum: yang wadahnya sudah punya aturan khusus dikecualikan.
        return $q->whereNotExists(fn ($sub) => $sub
            ->selectRaw('1')
            ->from('pallet_capacity_rules as k')
            ->whereColumn('k.pack_size', 'products.pack_size')
            ->whereRaw('k.pack_unit = UPPER(TRIM(products.pack_unit))')
            ->whereRaw('k.uom = UPPER(TRIM(products.uom))'))
            ->count();
    }

    /**
     * Ukuran yang dipakai produk tetapi belum punya aturan — pekerjaan yang
     * tersisa, diurutkan dari yang paling banyak produknya.
     *
     * Inilah yang membuat layar ini bisa dipakai sekali duduk: yang mengisinya
     * tidak perlu menebak ukuran apa saja yang kurang.
     */
    private function ukuranBelumPunyaAturan()
    {
        return Product::query()
            ->tanpaKapasitasPalet()
            ->whereNotNull('pack_unit')
            ->whereNotNull('pack_size')
            ->selectRaw('UPPER(TRIM(pack_unit)) AS pack_unit, pack_size, UPPER(TRIM(uom)) AS uom, COUNT(*) AS jumlah')
            ->groupByRaw('UPPER(TRIM(pack_unit)), pack_size, UPPER(TRIM(uom))')
            ->orderByDesc('jumlah')
            ->get();
    }

    /**
     * Produk yang ukuran kemasannya sendiri kosong.
     *
     * Aturan ukuran TIDAK BISA menolong mereka — tidak ada ukuran untuk
     * dicocokkan. Disebut terpisah supaya tidak terlihat seperti pekerjaan
     * yang bisa diselesaikan dari layar ini, padahal jalan keluarnya
     * melengkapi kemasannya di Master Produk.
     */
    private function tanpaUkuranKemasan(): int
    {
        return Product::query()
            ->whereNull('max_qty_per_pallet')
            ->where(fn ($q) => $q->whereNull('pack_unit')->orWhereNull('pack_size'))
            ->count();
    }
}
