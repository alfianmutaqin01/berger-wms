<?php

namespace App\Support\Outbound;

use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\SalesOrderOutstanding;

/**
 * Mencatat kekurangan pesanan ke riwayat outstanding.
 *
 * SATU PINTU untuk dua tempat yang menghasilkan kekurangan — penerimaan
 * (OrderApprovalController) dan pengiriman (Shipment). Keduanya memanggil
 * kelas ini alih-alih menulis sendiri ke tabel, supaya aturan "kapan sebuah
 * kekurangan layak dicatat" hanya ada di satu berkas dan tidak diam-diam
 * berbeda di antara keduanya.
 *
 * ATURANNYA: DICATAT SAAT ANGKANYA BERUBAH, BUKAN SETIAP KALI DILEWATI
 * -------------------------------------------------------------------
 * Pesanan 10 yang disetujui 5 sudah menghasilkan satu baris riwayat saat
 * diterima. Ketika 5 itu kemudian berangkat, Shipment menghitung ulang dan
 * mendapat kekurangan yang sama — 5. Kalau setiap perhitungan ulang ikut
 * dicatat, satu kekurangan yang sama muncul berkali-kali di layar dan
 * pembacanya menyimpulkan pesanan itu bermasalah berulang kali padahal
 * masalahnya cuma satu.
 *
 * Jadi yang dicatat hanya PERUBAHAN: kekurangan yang baru muncul, atau yang
 * membesar/mengecil dari angka yang sudah tercatat sebelumnya. Kekurangan yang
 * TERTUTUP (jadi nol) tidak menambah baris — penutupannya terbaca sendiri dari
 * `outstanding_qty` baris pesanannya, yang dipakai halaman Outstanding untuk
 * menandai baris riwayat lama sebagai "sudah terpenuhi".
 *
 * PEMBATALAN SENGAJA TIDAK DICATAT DI SINI. Pesanan yang dibatalkan kembali ke
 * antrean dan akan dinilai ulang dari awal; mencatat seluruh qty-nya sebagai
 * kekurangan berarti menghitung dua kali begitu penerimaan berikutnya jalan.
 * Jejak pembatalannya sudah lengkap di sales_order_cancellations.
 */
class OutstandingRecorder
{
    /**
     * Mencatat kekurangan pada satu baris pesanan, bila memang berubah.
     *
     * @param  int  $sisa  kekurangan setelah keputusan/peristiwa ini
     * @return bool apakah baris riwayat benar-benar ditambahkan
     */
    public function record(
        SalesOrder $order,
        SalesOrderDetail $detail,
        int $sisa,
        string $cause,
        ?int $userId,
        ?string $note = null,
    ): bool {
        if ($sisa <= 0) {
            return false;
        }

        if ($sisa === $this->sisaTercatatTerakhir($detail)) {
            return false;
        }

        SalesOrderOutstanding::create([
            'sales_order_id' => $order->id,
            'sales_order_detail_id' => $detail->id,
            'product_id' => $detail->product_id,
            'warehouse_id' => $order->warehouse_id,
            'cause' => $cause,
            'qty_ordered' => $detail->qty_ordered,
            // Yang benar-benar dipenuhi sejauh peristiwa ini, sehingga baris
            // riwayatnya bisa dibaca utuh tanpa membuka pesanannya.
            'qty_fulfilled' => max(0, (int) $detail->qty_ordered - $sisa),
            'qty_outstanding' => $sisa,
            'note' => $note,
            'recorded_by' => $userId,
        ]);

        return true;
    }

    /**
     * Kekurangan terakhir yang sudah tercatat untuk baris ini.
     *
     * NULL berarti belum pernah ada — bukan nol. Membedakan keduanya penting:
     * kekurangan 0 yang "sudah tercatat" tidak mungkin ada (dilarang CHECK di
     * migrasi), jadi menyamakannya akan membuat kekurangan pertama pada baris
     * yang belum punya riwayat ikut tersaring bila kebetulan bernilai nol.
     */
    private function sisaTercatatTerakhir(SalesOrderDetail $detail): ?int
    {
        $terakhir = SalesOrderOutstanding::query()
            ->where('sales_order_detail_id', $detail->id)
            ->latest('id')
            ->value('qty_outstanding');

        return $terakhir === null ? null : (int) $terakhir;
    }
}
