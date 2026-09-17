<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Pencatat log aktivitas — satu pintu untuk seluruh sistem.
 *
 * DICATAT DI TEMPAT KEJADIAN, BUKAN LEWAT OBSERVER MODEL. Observer tahu kolom
 * mana yang berubah tetapi tidak tahu MENGAPA: satu baris stok yang qty-nya
 * turun terlihat sama persis entah ia dikoreksi Manager, dipicking operator,
 * atau disahkan lewat stocktake. Yang perlu dibaca orang enam bulan kemudian
 * adalah maksudnya, dan maksud itu hanya diketahui di titik tindakannya.
 *
 * MENCATAT TIDAK BOLEH MENGGAGALKAN TINDAKANNYA. Kalau penulisan log gagal —
 * tabelnya belum ada, kolomnya berubah, disknya penuh — pemindahan stok yang
 * sudah sah TIDAK BOLEH ikut dibatalkan karena catatannya gagal ditulis.
 * Kegagalannya dibuang ke log aplikasi supaya tetap terlihat, dan tindakannya
 * jalan terus. Buku besar stok (stock_movements) tetap menjadi sumber
 * kebenaran angka; ini lapisan pengawasan di atasnya, bukan di bawahnya.
 *
 * NAMA & PERAN PELAKU DISALIN sebagai teks. Orang pindah jabatan dan akun
 * dihapus; log harus tetap terbaca sebagaimana keadaannya SAAT kejadian.
 */
class Activity
{
    /**
     * @param  array<string, mixed>  $properties  rincian bebas: nilai sebelum/
     *                                            sesudah, alasan, nomor batch
     */
    public static function record(
        string $action,
        string $description,
        ?Model $subject = null,
        ?int $warehouseId = null,
        array $properties = [],
        ?User $user = null,
    ): void {
        try {
            $pelaku = $user ?? Auth::user();

            ActivityLog::create([
                'user_id' => $pelaku?->id,
                'user_name' => $pelaku?->full_name,
                'user_role' => $pelaku?->role?->slug,
                'action' => $action,
                'description' => $description,
                'subject_type' => $subject !== null ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                // Nomor dokumennya DISALIN, bukan dibaca ulang lewat relasi
                // saat log dibuka: satu query per baris kalau dibaca ulang,
                // dan nomornya ikut hilang begitu dokumennya dihapus — justru
                // pada saat jejaknya paling dibutuhkan.
                'reference_number' => JenisTransaksi::nomor($subject),
                'warehouse_id' => $warehouseId,
                'properties' => $properties === [] ? null : $properties,
                // Request::ip() aman dipanggil dari command baris perintah —
                // hasilnya null, dan log tindakan sistem memang tidak punya IP.
                'ip_address' => Request::ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Sengaja ditelan. Lihat catatan kelas: catatan yang gagal ditulis
            // tidak boleh menjatuhkan tindakan yang sudah sah.
            Log::error('Gagal menulis log aktivitas: '.$e->getMessage(), [
                'action' => $action,
                'subject' => $subject !== null ? $subject::class.'#'.$subject->getKey() : null,
            ]);
        }
    }
}
