<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pengirim lonceng — satu pintu, seperti App\Support\Activity.
 *
 * MENGIRIM TIDAK BOLEH MENGGAGALKAN TINDAKANNYA. Alasannya sama persis
 * dengan Activity: pesanan yang sudah sah disetujui tidak boleh ikut batal
 * karena loncengnya gagal berbunyi. Kegagalan dibuang ke log aplikasi supaya
 * tetap terlihat, dan tindakannya jalan terus.
 *
 * PENERIMA DIPILIH LEWAT IZIN, BUKAN NAMA PERAN. `Permission::MATRIX` sudah
 * menjadi satu-satunya tempat yang memutuskan siapa boleh apa; menuliskan
 * lagi "kirim ke logistik dan manager" di sini berarti dua daftar yang harus
 * sepakat, dan suatu hari peran baru ditambahkan di satu tempat saja — lalu
 * ada orang yang halamannya bisa dibuka tetapi loncengnya tidak pernah
 * berbunyi.
 *
 * BATAS GUDANG IKUT BERLAKU. Logistik Pekanbaru tidak perlu tahu ada pesanan
 * masuk di Karawang. Akun tanpa gudang (Super Admin) menerima semuanya —
 * itulah arti warehouse_id NULL di seluruh sistem ini.
 *
 * TIDAK MENGIRIM KE DIRI SENDIRI. Orang yang baru saja menekan tombolnya
 * sudah melihat pesan hijau di layarnya; lonceng yang berbunyi untuk
 * pekerjaan sendiri hanya melatih orang mengabaikannya.
 */
class Notifier
{
    /**
     * Mengirim ke semua pemegang izin tertentu di satu gudang.
     *
     * @return int jumlah orang yang menerima
     */
    public static function toPermission(
        string $izin,
        ?int $warehouseId,
        string $type,
        string $title,
        string $body,
        ?string $url = null,
        ?Model $subject = null,
    ): int {
        try {
            $slug = Permission::MATRIX[$izin] ?? [];

            if ($slug === []) {
                return 0;
            }

            $penerima = User::query()
                ->where('is_active', true)
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $slug))
                ->when(
                    $warehouseId !== null,
                    // Akun tanpa gudang ikut menerima: itulah Super Admin,
                    // dan ia memang melihat seluruh gudang.
                    fn ($q) => $q->where(fn ($w) => $w
                        ->where('warehouse_id', $warehouseId)
                        ->orWhereNull('warehouse_id')),
                )
                ->when(auth()->id(), fn ($q, $id) => $q->whereKeyNot($id))
                ->pluck('id');

            return self::simpan($penerima->all(), $type, $title, $body, $url, $warehouseId, $subject);
        } catch (Throwable $e) {
            self::catatKegagalan($e, $type);

            return 0;
        }
    }

    /** Mengirim ke satu orang tertentu — mis. Sales pemilik pesanan. */
    public static function toUser(
        ?int $userId,
        string $type,
        string $title,
        string $body,
        ?string $url = null,
        ?int $warehouseId = null,
        ?Model $subject = null,
    ): int {
        if ($userId === null || $userId === auth()->id()) {
            return 0;
        }

        try {
            return self::simpan([$userId], $type, $title, $body, $url, $warehouseId, $subject);
        } catch (Throwable $e) {
            self::catatKegagalan($e, $type);

            return 0;
        }
    }

    /**
     * @param  array<int, int>  $userId
     */
    private static function simpan(
        array $userId,
        string $type,
        string $title,
        string $body,
        ?string $url,
        ?int $warehouseId,
        ?Model $subject,
    ): int {
        if ($userId === []) {
            return 0;
        }

        $waktu = now();

        // Satu insert untuk seluruh penerima. Lonceng dikirim dari dalam alur
        // yang sudah memegang kunci basis data; menyisipkan satu per satu
        // memperpanjang transaksi tanpa alasan.
        Notification::insert(array_map(fn ($id) => [
            'user_id' => $id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'warehouse_id' => $warehouseId,
            'read_at' => null,
            'created_at' => $waktu,
        ], $userId));

        return count($userId);
    }

    private static function catatKegagalan(Throwable $e, string $type): void
    {
        Log::error('Gagal mengirim notifikasi: '.$e->getMessage(), ['type' => $type]);
    }
}
