<?php

namespace App\Support\Messaging;

use App\Jobs\SendSalesOrderEmail;
use App\Models\SalesOrderEmail;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pintu tunggal untuk mengantrekan email kabar pesanan ke Sales.
 *
 * DIPANGGIL SETELAH TRANSAKSI SELESAI, di sebelah lonceng web yang sudah ada.
 * Kalau diantrekan dari dalam transaksi, job bisa berjalan lebih dulu daripada
 * commit dan membaca pesanan yang statusnya belum berubah — lalu melewatkan
 * emailnya karena "kabarnya belum benar".
 *
 * TIDAK PERNAH MELEMPAR, alasannya sama dengan App\Support\Notifier: pesanan
 * yang sudah sah diterima tidak boleh tampil gagal di layar Logistik hanya
 * karena catatan emailnya tidak bisa ditulis.
 */
final class EmailSales
{
    /**
     * @param  array<string, mixed>  $data  angka yang hanya benar pada saat kejadian ini
     */
    public static function antrekan(
        int $salesOrderId,
        string $jenis,
        ?int $deliveryNoteId = null,
        array $data = [],
    ): ?SalesOrderEmail {
        try {
            $email = SalesOrderEmail::create([
                'sales_order_id' => $salesOrderId,
                'delivery_note_id' => $deliveryNoteId,
                'type' => $jenis,
                'data' => $data === [] ? null : $data,
                'status' => SalesOrderEmail::STATUS_PENDING,
            ]);

            SendSalesOrderEmail::dispatch($email->id);

            return $email;
        } catch (Throwable $e) {
            Log::warning('Email kabar pesanan gagal diantrekan', [
                'sales_order_id' => $salesOrderId,
                'jenis' => $jenis,
                'galat' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
