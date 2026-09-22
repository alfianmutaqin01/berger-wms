<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\AcceptSalesOrderRequest;
use App\Models\SalesOrder;
use App\Support\Outbound\SoNumberFixer;
use App\Support\WarehouseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Nomor SO dari BC pada pesanan — diperiksa sebelum diterima, dan dikoreksi
 * bila salah ketik sesudahnya.
 */
class SoNumberController extends Controller
{
    /**
     * Memeriksa nomor SO sambil diketik, sebelum tombol Terima ditekan.
     *
     * Tanpa ini, satu-satunya cara Logistik tahu nomornya bentrok adalah
     * menekan Terima lalu ditolak — dan pada pesanan bermetode dokumen itu
     * berarti seluruh tempelan dari BC harus diulang. Jawabannya membedakan
     * tiga keadaan, karena tindak lanjutnya berbeda:
     *
     *   bebas          -> lanjut seperti biasa
     *   dapat_digabung -> pelanggan sama, tawarkan penggabungan invoice
     *   terpakai       -> pelanggan lain, tidak ada jalan selain memeriksa BC
     *
     * Sumber kebenarannya SATU dengan validasi (AcceptSalesOrderRequest::
     * pemegangNomorSo), supaya layar dan server tidak pernah berbeda jawaban.
     */
    public function checkSoNumber(Request $request, SalesOrder $order): JsonResponse
    {
        WarehouseScope::assert($order->warehouse_id, $request->user());

        $data = $request->validate(['bc_so_number' => ['required', 'string', 'max:50']]);

        $pemegang = AcceptSalesOrderRequest::pemegangNomorSo(
            $data['bc_so_number'],
            $request->user(),
            $order->id,
        );

        if ($pemegang === null) {
            return response()->json(['status' => 'bebas']);
        }

        $sama = $pemegang->customer_id === $order->customer_id;

        return response()->json([
            'status' => $sama ? 'dapat_digabung' : 'terpakai',
            'pesanan' => [
                'id' => $pemegang->id,
                'nomor' => $pemegang->order_number,
                'customer' => $pemegang->customer?->name,
                'diterima' => $pemegang->approved_at?->format('d M Y H:i'),
            ],
        ]);
    }

    /**
     * Koreksi manual nomor SO yang salah ketik (Fase 6 tahap 5).
     *
     * PINTU KECIL, bukan alur utama. Dipakai untuk salah ketik yang ketahuan
     * sendiri SEBELUM Surat Jalan-nya terbit — saat itu belum ada dokumen
     * yang bisa disalin. Setelah SJ terbit, jalannya lewat tombol Pasangkan
     * di Surat Jalan, supaya nomornya diambil dari dokumen BC dan bukan
     * diketik ulang oleh jari yang tadi salah. Batasnya ditegakkan
     * SoNumberFixer, bukan di sini.
     */
    public function renameSoNumber(Request $request, SalesOrder $order, SoNumberFixer $koreksi): RedirectResponse
    {
        WarehouseScope::assert($order->warehouse_id, $request->user());

        $data = $request->validate([
            'bc_so_number' => ['required', 'string', 'max:50'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'bc_so_number.required' => 'Nomor SO yang benar wajib diisi.',
        ], [
            'bc_so_number' => 'nomor SO',
            'reason' => 'alasan koreksi',
        ]);

        $lama = $order->bc_so_number;

        try {
            $koreksi->rename($order, $data['bc_so_number'], $data['reason'] ?? null, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            'Nomor SO pesanan %s diubah dari %s menjadi %s. Perubahannya tercatat.',
            $order->order_number,
            $lama ?: '(kosong)',
            $order->fresh()->bc_so_number,
        ));
    }
}
