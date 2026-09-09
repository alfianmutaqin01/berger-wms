<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Lonceng notifikasi — Fase 9.
 *
 * MILIK PRIBADI, TIDAK DIPAGARI IZIN. Setiap peran punya loncengnya sendiri
 * dan hanya melihat isinya sendiri; yang menentukan apa yang masuk ke sana
 * sudah diputuskan di App\Support\Notifier saat pengirimannya. Menambahkan
 * gate di sini justru salah — ia akan memblokir orang dari suratnya sendiri.
 *
 * SEMUA PENYARINGAN LEWAT scopeMilik. Notifikasi memuat nomor pesanan dan
 * nama pelanggan; satu kelalaian di sini berarti Sales membaca pekerjaan
 * gudang lain.
 */
class NotificationController extends Controller
{
    /** Halaman "Semua Notifikasi". */
    public function index(Request $request): View
    {
        $notifikasi = Notification::query()
            ->milik($request->user()?->id)
            ->latest('created_at')
            ->latest('id')
            ->paginate(20);

        return view('wms.notifications', [
            'notifikasi' => $notifikasi,
            'belumDibaca' => Notification::query()
                ->milik($request->user()?->id)
                ->belumDibaca()
                ->count(),
        ]);
    }

    /**
     * Membuka satu notifikasi: ditandai dibaca lalu diantar ke halamannya.
     *
     * DUA HAL SEKALIGUS, dan itu disengaja. Menandai dibaca lewat tombol
     * terpisah berarti orang mengklik notifikasinya, mengerjakan pekerjaannya,
     * lalu kembali menemukan loncengnya masih merah — dan sesudah dua kali
     * begitu ia berhenti mempercayai angkanya.
     */
    public function open(Request $request, Notification $notification): RedirectResponse
    {
        // 404, bukan 403: nomor notifikasi orang lain tidak perlu diakui ada.
        abort_unless($notification->user_id === $request->user()?->id, 404);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return redirect($notification->url ?: route('wms.notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $jumlah = Notification::query()
            ->milik($request->user()?->id)
            ->belumDibaca()
            ->update(['read_at' => now()]);

        return back()->with('success', $jumlah > 0
            ? sprintf('%d notifikasi ditandai sudah dibaca.', $jumlah)
            : 'Tidak ada notifikasi yang belum dibaca.');
    }
}
