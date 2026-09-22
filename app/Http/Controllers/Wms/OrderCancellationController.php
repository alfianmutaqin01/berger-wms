<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SalesOrder;
use App\Models\SalesOrderCancellation;
use App\Support\Activity;
use App\Support\Outbound\OrderCanceller;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Pembatalan pesanan yang SUDAH diterima Logistik.
 *
 * Pesanan yang belum diterima tidak dibatalkan di sini — yang itu ditolak
 * lewat OrderApprovalController::reject().
 */
class OrderCancellationController extends Controller
{
    public function __construct(private readonly OrderCanceller $canceller) {}

    /**
     * Membatalkan pesanan yang SUDAH diterima — temuan lapangan.
     *
     * Customer bisa membatalkan setelah pesanan diterima, atau BC ternyata
     * tidak menyetujuinya. Di BC nomor SO yang gagal dipakai ulang untuk
     * pesanan berikutnya; tanpa jalan ini, nomor itu terkunci selamanya di
     * WMS dan pesanan berikutnya ditolak dengan alasan yang keliru.
     *
     * Seluruh aturannya ada di App\Support\Outbound\OrderCanceller.
     */
    public function cancel(Request $request, SalesOrder $order): RedirectResponse
    {
        WarehouseScope::assert($order->warehouse_id, $request->user());

        $data = $request->validate([
            'cancellation_source' => ['required', 'in:'.implode(',', array_keys(SalesOrderCancellation::SOURCE_LABELS))],
            'cancellation_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [], [
            'cancellation_source' => 'sumber pembatalan',
            'cancellation_reason' => 'alasan pembatalan',
        ]);

        try {
            $hasil = $this->canceller->cancel(
                $order,
                $data['cancellation_source'],
                $data['cancellation_reason'],
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::ORDER_CANCEL,
            sprintf(
                'Membatalkan pesanan %s (%s) — %s',
                $order->order_number,
                SalesOrderCancellation::SOURCE_LABELS[$data['cancellation_source']] ?? $data['cancellation_source'],
                $data['cancellation_reason'],
            ),
            $order,
            $order->warehouse_id,
            [
                'sumber' => $data['cancellation_source'],
                'alasan' => $data['cancellation_reason'],
                'qty_dilepas' => $hasil['qty_dilepas'],
                'nomor_so_dibebaskan' => $hasil['nomor_so'],
            ],
        );

        return redirect()->route('wms.approval.history')->with('warning', sprintf(
            'Pesanan %s dibatalkan. %d unit dikembalikan ke stok%s, dan pesanannya kembali ke antrean — '.
            'terima lagi bila sudah diperbaiki, atau tolak bila memang final.',
            $order->order_number,
            $hasil['qty_dilepas'],
            $hasil['nomor_so'] ? ", nomor SO {$hasil['nomor_so']} kembali bisa dipakai" : '',
        ));
    }
}
