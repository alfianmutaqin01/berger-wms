<?php

namespace App\Support\Returns;

use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\SalesReturn;
use App\Models\SalesReturnDetail;
use App\Models\User;
use App\Support\DocumentNumber;
use App\Support\Inventory\StockActivator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PENOLAKAN CUSTOMER — dari depan toko sampai kembali jadi stok.
 *
 * EMPAT LANGKAH, EMPAT ORANG BERBEDA
 * ----------------------------------
 *   1. report()   Sales, di depan toko, saat mengunggah foto Surat Jalan.
 *   2. approve()  Logistik menilai KLAIMNYA — barang masih di atas truk.
 *   3. putaway()  Operator menaikkan ke rak, memisah yang bagus dari yang DDP.
 *   4. verify()   Logistik menilai BARANGNYA, lalu stoknya resmi.
 *
 * KENAPA LOGISTIK DUA KALI, DAN KENAPA ITU BUKAN PENGULANGAN
 * -----------------------------------------------------------
 * Pertanyaan keduanya berbeda dan tidak bisa saling menggantikan. Saat
 * approve(), barangnya belum dilihat siapa pun — yang diperiksa hanya cocok
 * tidaknya klaim dengan Surat Jalan. Saat verify(), yang diperiksa barang
 * fisiknya: benarkah sekian unit kembali, dan benarkah kondisinya seperti
 * kata Operator.
 *
 * Yang membuat langkah keempat wajib ada adalah pemisahan bagus/DDP:
 * keputusan itu bernilai uang. Menandai barang bagus sebagai DDP
 * menyembunyikan kehilangan; menandai barang rusak sebagai bagus berarti
 * menjualnya ke customer berikutnya. Sistem ini sudah memisahkan
 * yang-mengerjakan dari yang-mengesahkan di tempat lain dengan alasan yang
 * sama persis — lihat Permission::STOCKTAKE_COUNT vs STOCKTAKE_MANAGE.
 *
 * BARANG TOLAKAN ADALAH JALUR MASUK PALING BERISIKO, bukan paling ringan: ia
 * sudah naik truk, mungkin seharian, lalu dibongkar dan ditolak customer.
 * Membebaskannya dari verifikasi juga akan membuatnya menjadi satu-satunya
 * cara memasukkan stok tanpa pemeriksaan.
 *
 * STOK TIDAK BERGERAK SAMA SEKALI SEBELUM verify(). Melapor, menyetujui, dan
 * menaikkan ke rak semuanya hanya menulis catatan. Itu disengaja: selama
 * belum diverifikasi, tidak ada satu pun angka di layar stok yang berubah,
 * jadi tidak ada yang bisa terjual berdasarkan barang yang belum diperiksa.
 */
class CustomerRejection
{
    public function __construct(private readonly StockActivator $activator) {}

    /**
     * Status pesanan yang penolakannya boleh dilaporkan.
     *
     * SHIPPING ikut bersama PROOF_UPLOADED, alasannya sama dengan unggah
     * bukti: Sales sering sudah berdiri di depan toko dan tahu barangnya
     * ditolak sebelum supir sempat menekan tautan konfirmasi.
     */
    public const BOLEH_LAPOR = [
        SalesOrder::STATUS_SHIPPING,
        SalesOrder::STATUS_PROOF_UPLOADED,
    ];

    /**
     * Sales melaporkan barang yang ditolak customer.
     *
     * @param  array<int, array{detail_id:int, qty:int}>  $baris
     *
     * @throws RuntimeException
     */
    public function report(SalesOrder $order, array $baris, string $alasan, ?int $userId): SalesReturn
    {
        if ($baris === []) {
            throw new RuntimeException('Pilih dulu barang mana yang ditolak customer.');
        }

        return DB::transaction(function () use ($order, $baris, $alasan, $userId) {
            $terkunci = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($terkunci->status, self::BOLEH_LAPOR, true)) {
                throw new RuntimeException(sprintf(
                    'Pesanan %s berstatus "%s". Penolakan hanya bisa dilaporkan saat barangnya '.
                    'sedang atau sudah dikirim.',
                    $terkunci->order_number,
                    $terkunci->status_label,
                ));
            }

            /*
             * SATU LAPORAN BERJALAN PER PESANAN.
             *
             * Laporan kedua yang dibuka sebelum yang pertama selesai akan
             * menagih barang yang sama dua kali ke gudang, dan Operator tidak
             * punya cara membedakan mana yang sudah ia naikkan.
             */
            $berjalan = SalesReturn::query()
                ->where('sales_order_id', $terkunci->id)
                ->whereNotIn('status', [SalesReturn::STATUS_REJECTED, SalesReturn::STATUS_VERIFIED])
                ->first();

            if ($berjalan) {
                throw new RuntimeException(sprintf(
                    'Pesanan ini sudah punya laporan penolakan %s yang belum selesai (%s). '.
                    'Selesaikan dulu yang itu.',
                    $berjalan->reference,
                    $berjalan->status_label,
                ));
            }

            $retur = SalesReturn::create([
                'reference' => DocumentNumber::forSalesReturn(),
                'sales_order_id' => $terkunci->id,
                'delivery_note_id' => $terkunci->deliveryNotes()->value('id'),
                'customer_id' => $terkunci->customer_id,
                'warehouse_id' => $terkunci->warehouse_id,
                'status' => SalesReturn::STATUS_REPORTED,
                'reason' => $alasan,
                'reported_by' => $userId,
                'reported_at' => now(),
            ]);

            foreach ($baris as $satu) {
                $this->tambahBaris($retur, $terkunci, (int) $satu['detail_id'], (int) $satu['qty']);
            }

            return $retur->refresh();
        });
    }

    /**
     * @throws RuntimeException
     */
    private function tambahBaris(SalesReturn $retur, SalesOrder $order, int $detailId, int $qty): void
    {
        if ($qty < 1) {
            throw new RuntimeException('Jumlah yang ditolak harus lebih dari nol.');
        }

        $detail = SalesOrderDetail::query()
            ->where('sales_order_id', $order->id)
            ->with('product:id,sku,name')
            ->find($detailId);

        if (! $detail) {
            throw new RuntimeException('Ada baris yang bukan bagian dari pesanan ini.');
        }

        /*
         * Tidak boleh menolak lebih banyak daripada yang dikirim.
         *
         * Dibandingkan dengan qty_shipped, bukan qty_ordered: yang bisa
         * ditolak hanyalah barang yang benar-benar berangkat. Pesanan 10 yang
         * baru terkirim 6 tidak mungkin ditolak 10 — dan kalau dibiarkan,
         * empat unit yang tidak pernah keluar gudang akan masuk lagi sebagai
         * stok yang tidak pernah ada.
         */
        $terkirim = (int) $detail->qty_shipped;

        if ($qty > $terkirim) {
            throw new RuntimeException(sprintf(
                '%s: yang terkirim %d, tidak bisa menolak %d.',
                $detail->product?->sku ?? 'Item',
                $terkirim,
                $qty,
            ));
        }

        // Batch dan tanggal produksi diambil dari yang BENAR-BENAR diambil
        // dari rak untuk baris ini. Tanpa keduanya, barang kembali sebagai
        // batch baru dan umurnya ikut mundur.
        $asal = $this->batchYangBerangkat($detail);

        SalesReturnDetail::create([
            'sales_return_id' => $retur->id,
            'sales_order_detail_id' => $detail->id,
            'product_id' => $detail->product_id,
            'batch_no' => $asal['batch_no'],
            'production_date' => $asal['production_date'],
            'qty_rejected' => $qty,
        ]);
    }

    /**
     * Batch mana yang berangkat untuk baris pesanan ini.
     *
     * Diambil dari baris picking yang benar-benar diambil dari rak. Kalau
     * pesanannya dipicking lebih dari satu putaran, yang dipakai adalah yang
     * TERAKHIR berangkat — itulah yang sedang ditolak customer sekarang.
     *
     * @return array{batch_no:string, production_date:string}
     */
    private function batchYangBerangkat(SalesOrderDetail $detail): array
    {
        $baris = DB::table('picking_list_items')
            ->where('sales_order_detail_id', $detail->id)
            ->whereNotNull('picked_at')
            ->orderByDesc('picked_at')
            ->orderByDesc('id')
            ->first(['batch_no', 'production_date']);

        if ($baris && $baris->batch_no) {
            return [
                'batch_no' => $baris->batch_no,
                'production_date' => (string) $baris->production_date,
            ];
        }

        /*
         * Tidak ketemu — biasanya karena pesanannya masuk lewat impor Surat
         * Jalan BC dan tidak pernah melewati picking di sistem ini.
         *
         * Dilempar, BUKAN dikarang. Batch dan tanggal produksi menentukan
         * kedaluwarsa dan urutan FIFO; menebaknya berarti menempatkan barang
         * di antrean yang salah selama sisa umurnya, dan kesalahan itu tidak
         * pernah terlihat sampai ada yang mengirim barang kedaluwarsa.
         */
        throw new RuntimeException(sprintf(
            'Batch untuk %s tidak ditemukan di catatan picking, jadi barangnya tidak bisa '.
            'dikembalikan ke rak dengan umur yang benar. Laporkan ke Logistik untuk dicatat manual.',
            $detail->product?->sku ?? 'item ini',
        ));
    }

    /**
     * Logistik menyetujui klaimnya. Barang belum bergerak; ini menilai
     * kertasnya, bukan barangnya.
     *
     * @param  array<int, int>  $qtyDisetujui  id baris retur => qty disetujui
     *
     * @throws RuntimeException
     */
    public function approve(SalesReturn $retur, array $qtyDisetujui, ?string $catatan, ?int $userId): void
    {
        DB::transaction(function () use ($retur, $qtyDisetujui, $catatan, $userId) {
            $terkunci = SalesReturn::query()->lockForUpdate()->findOrFail($retur->id);

            $this->pastikanStatus($terkunci, [SalesReturn::STATUS_REPORTED], 'disetujui');

            $baris = $terkunci->details()->lockForUpdate()->get();
            $adaYangDisetujui = false;

            foreach ($baris as $satu) {
                $qty = (int) ($qtyDisetujui[$satu->id] ?? $satu->qty_rejected);

                if ($qty < 0 || $qty > $satu->qty_rejected) {
                    throw new RuntimeException(sprintf(
                        'Jumlah yang disetujui untuk satu baris harus antara 0 dan %d.',
                        $satu->qty_rejected,
                    ));
                }

                $satu->forceFill(['qty_approved' => $qty])->save();

                if ($qty > 0) {
                    $adaYangDisetujui = true;
                }
            }

            /*
             * Menyetujui NOL untuk seluruh baris sama dengan menolak
             * laporannya — dan lebih jujur dicatat begitu daripada
             * meninggalkan dokumen "disetujui" yang tidak menyuruh siapa pun
             * mengerjakan apa pun, lalu menggantung selamanya di antrean
             * put-away Operator.
             */
            $terkunci->forceFill([
                'status' => $adaYangDisetujui
                    ? SalesReturn::STATUS_PUTAWAY_PENDING
                    : SalesReturn::STATUS_REJECTED,
                'approved_by' => $userId,
                'approved_at' => now(),
                'approval_note' => $catatan,
            ])->save();
        });
    }

    /**
     * Logistik menolak laporannya — barang tidak masuk rak sama sekali.
     *
     * @throws RuntimeException
     */
    public function reject(SalesReturn $retur, string $alasan, ?int $userId): void
    {
        DB::transaction(function () use ($retur, $alasan, $userId) {
            $terkunci = SalesReturn::query()->lockForUpdate()->findOrFail($retur->id);

            $this->pastikanStatus($terkunci, [SalesReturn::STATUS_REPORTED], 'ditolak');

            $terkunci->forceFill([
                'status' => SalesReturn::STATUS_REJECTED,
                'approved_by' => $userId,
                'approved_at' => now(),
                'approval_note' => $alasan,
            ])->save();
        });
    }

    /**
     * Operator menaikkan satu baris ke rak, memisah yang bagus dari yang DDP.
     *
     * MASIH BELUM MENYENTUH STOK. Yang tercatat di sini hanya niat dan
     * alamatnya; angka stoknya baru berubah saat verify(). Itu yang membuat
     * langkah ini aman dikerjakan Operator sendirian.
     *
     * @throws RuntimeException
     */
    public function putaway(
        SalesReturnDetail $detail,
        int $qtyGood,
        int $qtyDdp,
        ?string $kodeRakGood,
        ?string $kodeRakDdp,
        ?string $catatan,
        ?int $userId,
    ): void {
        DB::transaction(function () use ($detail, $qtyGood, $qtyDdp, $kodeRakGood, $kodeRakDdp, $catatan, $userId) {
            $terkunci = SalesReturnDetail::query()->lockForUpdate()->findOrFail($detail->id);
            $retur = $terkunci->salesReturn;

            $this->pastikanStatus(
                $retur,
                [SalesReturn::STATUS_PUTAWAY_PENDING, SalesReturn::STATUS_VERIFICATION_PENDING, SalesReturn::STATUS_PARTIAL_VERIFIED],
                'dinaikkan ke rak',
            );

            if ($terkunci->is_verified) {
                throw new RuntimeException(
                    'Baris ini sudah diverifikasi Logistik dan stoknya sudah masuk — '.
                    'tidak bisa dinaikkan ulang. Pakai Koreksi Stok kalau angkanya keliru.'
                );
            }

            if ($qtyGood < 0 || $qtyDdp < 0) {
                throw new RuntimeException('Jumlah tidak boleh negatif.');
            }

            if ($qtyGood + $qtyDdp < 1) {
                throw new RuntimeException('Isi jumlahnya dulu — minimal satu unit, bagus atau DDP.');
            }

            $disetujui = (int) $terkunci->qty_approved;

            /*
             * TIDAK BOLEH LEBIH dari yang disetujui, tapi BOLEH KURANG.
             *
             * Lebih berarti barang yang tidak pernah disetujui ikut masuk jadi
             * stok. Kurang justru sering terjadi dan harus bisa dicatat apa
             * adanya — sebagian hilang atau pecah di perjalanan pulang —
             * karena itulah selisih yang harus dijawab saat verifikasi.
             */
            if ($qtyGood + $qtyDdp > $disetujui) {
                throw new RuntimeException(sprintf(
                    'Yang disetujui %d unit, tapi yang dinaikkan %d. Tidak bisa menaikkan lebih '.
                    'banyak daripada yang disetujui.',
                    $disetujui,
                    $qtyGood + $qtyDdp,
                ));
            }

            $terkunci->forceFill([
                'qty_good' => $qtyGood,
                'qty_ddp' => $qtyDdp,
                'location_id' => $qtyGood > 0 ? $this->rak($kodeRakGood, $retur->warehouse_id, 'barang bagus') : null,
                'ddp_location_id' => $qtyDdp > 0 ? $this->rak($kodeRakDdp, $retur->warehouse_id, 'barang DDP') : null,
                'condition_note' => $catatan,
                'putaway_by' => $userId,
                'putaway_at' => now(),
            ])->save();

            // Begitu seluruh barisnya naik, dokumennya pindah ke antrean
            // Logistik dengan sendirinya — tidak ada tombol "kirim" yang bisa
            // lupa ditekan lalu membuat barang menggantung di rak tanpa ada
            // yang tahu ia menunggu diperiksa.
            if ($retur->status === SalesReturn::STATUS_PUTAWAY_PENDING
                && $retur->fresh()->seluruhBarisSudahNaikRak()) {
                $retur->forceFill(['status' => SalesReturn::STATUS_VERIFICATION_PENDING])->save();
            }
        });
    }

    /**
     * Logistik memverifikasi baris-baris yang sudah di rak. DI SINILAH stok
     * benar-benar bertambah.
     *
     * @param  list<int>  $barisId
     * @return int jumlah baris yang berhasil diverifikasi
     *
     * @throws RuntimeException
     */
    public function verify(SalesReturn $retur, array $barisId, ?int $userId): int
    {
        if ($barisId === []) {
            throw new RuntimeException('Pilih dulu baris mana yang diverifikasi.');
        }

        return DB::transaction(function () use ($retur, $barisId, $userId) {
            $terkunci = SalesReturn::query()->lockForUpdate()->findOrFail($retur->id);

            $this->pastikanStatus(
                $terkunci,
                [SalesReturn::STATUS_VERIFICATION_PENDING, SalesReturn::STATUS_PARTIAL_VERIFIED],
                'diverifikasi',
            );

            $baris = $terkunci->details()
                ->whereIn('id', $barisId)
                ->where('is_verified', false)
                ->whereNotNull('putaway_at')
                ->with('product:id,sku,shelf_life_months')
                ->lockForUpdate()
                ->get();

            if ($baris->isEmpty()) {
                throw new RuntimeException(
                    'Tidak ada baris yang bisa diverifikasi — semuanya sudah diverifikasi '.
                    'atau belum dinaikkan Operator.'
                );
            }

            foreach ($baris as $satu) {
                if ((int) $satu->qty_good > 0) {
                    $this->activator->activateReturn(
                        $satu,
                        (int) $satu->location_id,
                        (int) $terkunci->warehouse_id,
                        (int) $satu->qty_good,
                        InventoryStock::STATUS_ACTIVE,
                        $userId,
                    );
                }

                if ((int) $satu->qty_ddp > 0) {
                    $this->activator->activateReturn(
                        $satu,
                        (int) $satu->ddp_location_id,
                        (int) $terkunci->warehouse_id,
                        (int) $satu->qty_ddp,
                        InventoryStock::STATUS_DDP,
                        $userId,
                    );
                }

                $satu->forceFill([
                    'is_verified' => true,
                    'verified_by' => $userId,
                    'verified_at' => now(),
                ])->save();
            }

            $selesai = $terkunci->fresh()->seluruhBarisTerverifikasi();

            $terkunci->forceFill([
                'status' => $selesai ? SalesReturn::STATUS_VERIFIED : SalesReturn::STATUS_PARTIAL_VERIFIED,
                'verified_by' => $selesai ? $userId : null,
                'verified_at' => $selesai ? now() : null,
            ])->save();

            return $baris->count();
        });
    }

    /* ------------------------------------------------------------- Dalam */

    /** @param list<string> $boleh */
    private function pastikanStatus(SalesReturn $retur, array $boleh, string $tindakan): void
    {
        if (! in_array($retur->status, $boleh, true)) {
            throw new RuntimeException(sprintf(
                'Laporan %s berstatus "%s", jadi tidak bisa %s sekarang.',
                $retur->reference,
                $retur->status_label,
                $tindakan,
            ));
        }
    }

    /**
     * Rak tujuan, dicari DI DALAM gudangnya sendiri.
     *
     * Kode rak tidak unik antar gudang — mencarinya tanpa menyebut gudang
     * akan menaruh barang di gudang lain, persis kesalahan yang pernah
     * terjadi pada formulir tambah stok.
     *
     * @throws RuntimeException
     */
    private function rak(?string $kode, int $warehouseId, string $untuk): int
    {
        if (blank($kode)) {
            throw new RuntimeException(sprintf('Rak untuk %s belum diisi.', $untuk));
        }

        $lokasi = Location::query()
            ->where('warehouse_id', $warehouseId)
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($kode))])
            ->first();

        if (! $lokasi) {
            throw new RuntimeException(sprintf(
                'Rak "%s" tidak ada di gudang ini.',
                $kode,
            ));
        }

        return $lokasi->id;
    }

    /** Baris pesanan yang masih boleh dilaporkan ditolak. */
    public function barisBolehDitolak(SalesOrder $order): Collection
    {
        return SalesOrderDetail::query()
            ->where('sales_order_id', $order->id)
            ->where('qty_shipped', '>', 0)
            ->with('product:id,sku,name,uom')
            ->get();
    }

    /** Apakah user ini boleh melaporkan penolakan untuk pesanan itu? */
    public function bolehMelapor(?SalesOrder $order, ?User $user): bool
    {
        return $order !== null
            && $user !== null
            && $order->user_id === $user->id
            && in_array($order->status, self::BOLEH_LAPOR, true);
    }
}
