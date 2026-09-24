<?php

namespace App\Support\Inventory;

use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Memindahkan stok dari satu rak ke rak lain DI DALAM satu gudang.
 *
 * Bukan transfer antar gudang — yang itu punya dokumen dan picking sendiri di
 * StockTransferController.
 *
 * KENAPA BERDIRI SENDIRI. Dua layar memakai perpindahan yang sama: "Pindah
 * rak" di Data Stok, dan pemindahan stok DDP ke rak DDP. Isinya bukan sekadar
 * menulis location_id baru — ada penguncian baris, penggabungan batch kembar
 * di rak tujuan, penyalinan seluruh penanda batch, dan sepasang entri buku
 * besar yang harus berjumlah nol. Disalin ke layar kedua, salinan itu pasti
 * menua sendiri-sendiri, dan yang ketinggalan barulah ketahuan sebagai stok
 * yang tercipta dari udara.
 */
class PemindahanRak
{
    /**
     * @throws RuntimeException kalau qty melebihi stok yang tersedia saat
     *                          transaksi benar-benar berjalan
     */
    public function pindahkan(
        InventoryStock $stock,
        Location $tujuan,
        int $qty,
        string $alasan,
        ?User $oleh,
    ): void {
        /*
         * DIKUNCI DAN DIBACA ULANG DI DALAM TRANSAKSI (temuan SQA).
         *
         * Sebelumnya qty asal dibaca saat permintaan masuk lalu ditulis sebagai
         * "angka lama dikurangi qty". Klik ganda pada tombol Pindahkan
         * menjalankan dua permintaan yang sama-sama membaca 100: keduanya lolos
         * pemeriksaan, asal ditulis 40 dua kali, tetapi rak tujuan bertambah 60
         * dua kali — 60 unit tercipta dari udara. Picking atau alokasi yang
         * mengubah baris yang sama di antaranya juga tertimpa diam-diam.
         */
        DB::transaction(function () use ($stock, $tujuan, $qty, $alasan, $oleh) {
            $asal = InventoryStock::query()->lockForUpdate()->findOrFail($stock->id);

            if ($qty > $asal->qty_available) {
                throw new RuntimeException(sprintf(
                    'Qty pindah (%d) melebihi stok tersedia (%d). Stoknya baru saja berubah — muat ulang halaman.',
                    $qty,
                    $asal->qty_available
                ));
            }

            $asalSebelum = $asal->qty_available;
            $asal->qty_available = $asalSebelum - $qty;
            $asal->save();

            // Batch yang sama di rak tujuan digabung, bukan dibuat baris baru
            // kembar yang harus dijumlahkan manual setiap kali dilihat. Ikut
            // dikunci: dua pemindahan ke rak yang sama tidak boleh saling
            // menimpa jumlahnya.
            $kunciTujuan = [
                'product_id' => $asal->product_id,
                'location_id' => $tujuan->id,
                'batch_no' => $asal->batch_no,
                'production_date' => $asal->production_date->toDateString(),
            ];
            $tujuanStok = InventoryStock::query()->where($kunciTujuan)->lockForUpdate()->first()
                ?? new InventoryStock($kunciTujuan);

            $tujuanSebelum = $tujuanStok->exists ? $tujuanStok->qty_available : 0;

            if (! $tujuanStok->exists) {
                $tujuanStok->fill([
                    'warehouse_id' => $asal->warehouse_id,
                    'qty_allocated' => 0,
                    // Kedaluwarsa & status IKUT dari asalnya, tidak dihitung ulang.
                    'expiry_date' => $asal->expiry_date->toDateString(),
                    'status' => $asal->status,
                    'ddp_reason' => $asal->ddp_reason,
                    'inbound_detail_id' => $asal->inbound_detail_id,
                    'verified_by' => $asal->verified_by,
                    'verified_at' => $asal->verified_at,

                    // SELURUH PENANDA BATCH IKUT PINDAH. Ketiganya melekat pada
                    // batch — pada apa yang terjadi saat produksi/pengujian —
                    // bukan pada rak tempat barangnya kebetulan duduk. Baris
                    // baru tanpa penanda akan membuat separuh batch dikarantina
                    // dan separuhnya bebas dijual, padahal barangnya sama.
                    //
                    // Metadata karantina WAJIB ikut, bukan cuma statusnya:
                    // CHECK inventory_stocks_karantina_lengkap menolak baris
                    // berstatus 'quarantine' yang tanggal & pemasangnya kosong,
                    // jadi tanpa ini memindahkan batch terkarantina gagal
                    // dengan galat constraint mentah.
                    'quarantine_days' => $asal->quarantine_days,
                    'quarantine_until' => $asal->quarantine_until?->toDateString(),
                    'quarantined_at' => $asal->quarantined_at,
                    'quarantined_by' => $asal->quarantined_by,
                    'quarantine_note' => $asal->quarantine_note,
                    'quarantine_released_at' => $asal->quarantine_released_at,

                    'has_quality_issue' => $asal->has_quality_issue,

                    'prioritize_out' => $asal->prioritize_out,
                    'prioritize_reason' => $asal->prioritize_reason,
                    'prioritized_at' => $asal->prioritized_at,
                    'prioritized_by' => $asal->prioritized_by,
                    'prioritize_released_at' => $asal->prioritize_released_at,
                ]);
            }

            $tujuanStok->qty_available = $tujuanSebelum + $qty;
            $tujuanStok->save();

            $jejak = [
                'product_id' => $asal->product_id,
                'warehouse_id' => $asal->warehouse_id,
                'reference_type' => StockMovement::REF_STOCK_TRANSFER,
                'reference_id' => $asal->id,
                'batch_no' => $asal->batch_no,
                'notes' => $alasan,
                'user_id' => $oleh?->id,
            ];

            StockMovement::create($jejak + [
                'location_id' => $asal->location_id,
                'movement_type' => StockMovement::TYPE_TRANSFER_OUT,
                'qty_change' => -$qty,
                'qty_before' => $asalSebelum,
                'qty_after' => $asal->qty_available,
            ]);

            StockMovement::create($jejak + [
                'location_id' => $tujuan->id,
                'movement_type' => StockMovement::TYPE_TRANSFER_IN,
                'qty_change' => $qty,
                'qty_before' => $tujuanSebelum,
                'qty_after' => $tujuanStok->qty_available,
            ]);
        });
    }
}
