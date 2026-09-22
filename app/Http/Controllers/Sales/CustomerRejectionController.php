<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\SalesOrder;
use App\Support\Activity;
use App\Support\Notifier;
use App\Support\Permission;
use App\Support\Returns\CustomerRejection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Sisi Sales dari Penolakan Customer — laporan dari depan toko.
 *
 * Pemeriksaan fisik dan keputusan atas laporannya ada di sisi Logistik,
 * App\Http\Controllers\Wms\CustomerRejectionController.
 */
class CustomerRejectionController extends Controller
{
    /**
     * Sales melaporkan barang yang ditolak customer, dari depan toko.
     *
     * MENEMPEL DI HALAMAN DETAIL PESANAN, bukan halaman sendiri — aturan yang
     * sama dengan unggah bukti Surat Jalan: keduanya dikerjakan bersamaan
     * dalam satu kunjungan, sambil memegang HP, sebelum truk pergi.
     */
    public function reportReturn(Request $request, CustomerRejection $penolakan): RedirectResponse
    {
        $order = SalesOrder::query()->findOrFail((int) $request->input('order_id'));

        // Hanya Sales pemilik pesanan; 404, bukan 403, supaya nomor pesanan
        // orang lain tidak terkonfirmasi ada. Aturan yang sama dengan
        // SalesOrderController dan DeliveryProofController.
        abort_unless($order->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            // Alasan WAJIB dan tidak boleh sepatah kata. Logistik yang
            // menilai klaim ini tidak ikut ke toko; kalimat "ditolak" saja
            // tidak memberinya apa pun untuk dinilai.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'qty' => ['required', 'array', 'min:1'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
        ], [], ['reason' => 'alasan penolakan', 'qty' => 'jumlah yang ditolak']);

        // Baris berjumlah nol atau kosong bukan kesalahan — Sales mencentang
        // sebagian item saja dan sisanya dibiarkan kosong.
        $baris = [];

        foreach ($data['qty'] as $detailId => $qty) {
            if ((int) $qty > 0) {
                $baris[] = ['detail_id' => (int) $detailId, 'qty' => (int) $qty];
            }
        }

        try {
            $retur = $penolakan->report($order, $baris, $data['reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::RETURN_REPORT,
            sprintf(
                'Melaporkan penolakan %s pada pesanan %s (%d baris) — %s',
                $retur->reference,
                $order->order_number,
                count($baris),
                $data['reason'],
            ),
            $retur,
            $order->warehouse_id,
            ['pesanan' => $order->order_number, 'baris' => $baris, 'alasan' => $data['reason']],
        );

        Notifier::toPermission(
            Permission::RETURN_APPROVE,
            $order->warehouse_id,
            Notification::RETURN_REPORTED,
            'Laporan penolakan customer baru',
            sprintf(
                '%s pada pesanan %s (%s) — %s',
                $retur->reference,
                $order->order_number,
                $order->customer?->name ?? 'pelanggan',
                $data['reason'],
            ),
            route('wms.returns.show', $retur),
            $retur,
        );

        return back()->with('success', sprintf(
            'Laporan penolakan %s terkirim. Logistik akan memeriksanya bersama foto Surat Jalan Anda.',
            $retur->reference,
        ));
    }
}
