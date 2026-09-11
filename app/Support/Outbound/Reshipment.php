<?php

namespace App\Support\Outbound;

use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\SalesOrderReshipment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mengirim ulang kekurangan sebuah pesanan — permintaan pemilik produk.
 *
 * NOMOR SO SAMA, SURAT JALAN BARU. Pesanannya memang pesanan yang itu juga;
 * yang baru adalah keberangkatan kendaraannya. Karena itu TIDAK ada pesanan
 * baru yang dibuat di sini — pesanan yang sama dibuka kembali untuk putaran
 * berikutnya, dan Surat Jalan barunya terbit sendiri dari sistem BC setelah
 * barangnya dipicking ulang.
 *
 * MEMBUAT PESANAN BARU ADALAH JALAN YANG SALAH, dan menggoda karena terlihat
 * lebih sederhana. Akibatnya: satu kewajiban terbaca sebagai dua pesanan,
 * angka penjualan terhitung dua kali, dan pelanggan menerima dua nomor SO
 * untuk satu pembelian yang ia lakukan sekali.
 *
 * PUTARAN, BUKAN PENGULANGAN. Sistem ini sudah bisa memicking satu pesanan
 * lebih dari sekali (lihat PickingListItem::scopeForOrderRound): tiap putaran
 * dibatasi picking_list_id-nya sendiri, sehingga "yang diambil dari rak" pada
 * putaran kedua tidak ikut menghitung barang yang sudah berangkat pada putaran
 * pertama. Kelas ini hanya membuka putaran berikutnya; seluruh mesin picking
 * dan pengiriman sesudahnya berjalan apa adanya, tanpa cabang khusus.
 *
 * YANG DIALOKASIKAN HANYA SEBANYAK YANG ADA. Sama seperti penerimaan pesanan:
 * kalau stoknya cuma cukup untuk sebagian, sebagian itulah yang dicadangkan
 * dan sisanya TETAP terutang untuk putaran berikutnya. Menolak seluruhnya
 * karena kurang berarti pelanggan menunggu lebih lama untuk barang yang
 * sebenarnya sudah ada di rak.
 */
class Reshipment
{
    public function __construct(private readonly FifoAllocator $allocator) {}

    /**
     * Status pesanan yang putaran pengirimannya SUDAH SELESAI.
     *
     * Hanya dari sinilah putaran baru boleh dibuka. Pesanan yang masih
     * approved/picking/siap-kirim sedang berjalan putarannya sendiri —
     * membuka putaran baru di atasnya akan mencadangkan stok dua kali untuk
     * kekurangan yang sama.
     */
    public const STATUS_BOLEH = [
        SalesOrder::STATUS_SHIPPING,
        SalesOrder::STATUS_PROOF_UPLOADED,
        SalesOrder::STATUS_COMPLETED,
        SalesOrder::STATUS_COMPLETED_BILLING,
    ];

    /**
     * Membuka putaran pengiriman berikutnya atas kekurangan sebuah pesanan.
     *
     * @return array{putaran:int, diminta:int, didapat:int, baris:list<array{sku:string, diminta:int, didapat:int}>}
     *
     * @throws RuntimeException
     */
    public function open(SalesOrder $order, ?string $catatan, ?int $userId): array
    {
        return DB::transaction(function () use ($order, $catatan, $userId) {
            $terkunci = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($terkunci->status, self::STATUS_BOLEH, true)) {
                throw new RuntimeException(sprintf(
                    'Pesanan %s berstatus "%s". Pengiriman ulang hanya bisa dibuka setelah putaran sebelumnya '.
                    'benar-benar berangkat — kalau tidak, stok yang sama dicadangkan dua kali untuk kekurangan yang sama.',
                    $terkunci->order_number,
                    $terkunci->status_label,
                ));
            }

            $baris = SalesOrderDetail::query()
                ->where('sales_order_id', $terkunci->id)
                ->where('outstanding_qty', '>', 0)
                ->with('product:id,sku')
                ->lockForUpdate()
                ->get();

            if ($baris->isEmpty()) {
                throw new RuntimeException(sprintf(
                    'Pesanan %s tidak punya kekurangan yang tersisa — seluruhnya sudah terkirim.',
                    $terkunci->order_number,
                ));
            }

            $diminta = (int) $baris->sum('outstanding_qty');

            /*
             * PUTARAN LAMA DILEPAS SEBELUM YANG BARU DIBUKA.
             *
             * picking_list_id menunjuk daftar picking putaran sebelumnya yang
             * SUDAH selesai. Dibiarkan terisi, dua hal rusak sekaligus:
             * PickingListBuilder menolak pesanan ini masuk daftar baru (ia
             * menyaring picking_list_id NULL supaya satu pesanan tidak masuk
             * dua daftar sekaligus), dan PendingAllocationFiller melewatinya
             * sehingga stok yang datang belakangan tidak pernah mengisinya.
             *
             * Daftar picking yang lama TIDAK hilang: baris-barisnya tetap
             * menunjuk pesanan ini, dan scopeForOrderRound yang memisahkan
             * putaran mana milik siapa.
             */
            $terkunci->forceFill([
                'picking_list_id' => null,
                'status' => SalesOrder::STATUS_APPROVED,
                // Argo SLA dimulai ulang untuk putaran ini. Membiarkan
                // shipped_at putaran pertama membuat pesanan ini terbaca
                // "sudah berangkat" padahal sisanya belum bergerak.
                'shipped_at' => null,
            ])->save();

            $rincian = [];
            $didapat = 0;

            foreach ($baris as $detail) {
                $kurang = (int) $detail->outstanding_qty;
                $dapat = $this->allocator->allocate($detail, $kurang, $userId);

                $didapat += $dapat;
                $rincian[] = [
                    'sku' => $detail->product?->sku ?? '—',
                    'diminta' => $kurang,
                    'didapat' => $dapat,
                ];
            }

            $putaran = SalesOrderReshipment::where('sales_order_id', $terkunci->id)->max('round_no');
            $putaran = ((int) $putaran) + 1;

            SalesOrderReshipment::create([
                'sales_order_id' => $terkunci->id,
                'warehouse_id' => $terkunci->warehouse_id,
                'round_no' => $putaran,
                'qty_outstanding' => $diminta,
                'qty_allocated' => $didapat,
                'note' => $catatan,
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            return [
                'putaran' => $putaran,
                'diminta' => $diminta,
                'didapat' => $didapat,
                'baris' => $rincian,
            ];
        });
    }

    /**
     * Apakah pesanan ini bisa dikirim ulang sekarang.
     *
     * Dipakai layar untuk menampilkan tombolnya. Penegakan sebenarnya tetap
     * di open(), di dalam kunci — angka di layar sudah basi begitu terbaca.
     */
    public function bolehDikirimUlang(?SalesOrder $order): bool
    {
        return $order !== null
            && in_array($order->status, self::STATUS_BOLEH, true)
            && $order->details()->where('outstanding_qty', '>', 0)->exists();
    }
}
