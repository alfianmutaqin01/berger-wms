<?php

namespace App\Jobs;

use App\Mail\PermintaanLupaSandi;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\User;
use App\Support\Activity;
use App\Support\Notifier;
use App\Support\Permission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Meneruskan "Lupa sandi?" ke admin yang berwenang atas akun itu.
 *
 * KENAPA LEWAT ANTREAN. Kalau dikerjakan saat permintaan berlangsung, email
 * yang terdaftar dijawab lebih lambat (mencari penerima, menulis lonceng,
 * mengantre email) daripada yang tidak — dan selisih waktu itu cukup untuk
 * memetakan daftar email karyawan dari luar. PRD v1.5 menutup persis celah
 * itu di halaman login; halaman ini tidak boleh membukanya kembali. Di sini
 * formulirnya hanya mengantrekan, dan jawaban ke pengunjung selalu sama.
 *
 * TIDAK ADA YANG DIKIRIM KE PEMILIK AKUN. Kalau seseorang sedang mencoba
 * mengambil alih akun orang lain, email "sandi Anda sedang direset" ke kotak
 * masuk korban tidak menghentikan apa pun — yang menghentikannya adalah admin
 * yang menghubungi orangnya dan memastikan permintaan itu memang darinya.
 */
class ProsesPermintaanLupaSandi implements ShouldQueue
{
    use Queueable;

    /**
     * Jeda minimum antara dua permintaan untuk akun yang sama. Orang yang
     * panik menekan tombolnya lima kali cukup membunyikan lonceng admin
     * sekali; begitu pula siapa pun yang sengaja membanjirinya.
     */
    public const JEDA_MENIT = 60;

    public int $tries = 3;

    public function __construct(
        public readonly string $email,
        public readonly ?string $ip,
    ) {}

    public function handle(): void
    {
        $user = User::query()
            ->with('role')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($this->email))])
            ->where('is_active', true)
            ->first();

        // Tidak terdaftar, atau tidak aktif. Diam, dan tidak dicatat:
        // formulir tanpa login tidak boleh bisa menulis baris log tanpa batas.
        // Akun non-aktif sengaja ikut diam — yang perlu dilakukan untuknya
        // adalah pengaktifan kembali, dan itu keputusan yang berbeda.
        if ($user === null) {
            return;
        }

        if ($user->password_reset_requested_at?->gt(now()->subMinutes(self::JEDA_MENIT))) {
            return;
        }

        $user->forceFill(['password_reset_requested_at' => now()])->save();

        $judul = 'Permintaan reset sandi';
        $isi = sprintf(
            '%s (%s, %s) menekan "Lupa sandi?". Hubungi orangnya untuk memastikan permintaan ini '
            .'memang darinya, lalu isi sandi sementara di Manajemen Pengguna.',
            $user->full_name,
            $user->role?->name ?? 'tanpa peran',
            $user->email,
        );
        $tautan = route('wms.users.index', ['search' => $user->email]);

        // Super Admin seluruh gudang, dan Manager di gudang akun itu saja —
        // cakupan yang sama dengan siapa yang boleh mengubah akun tersebut.
        Notifier::toPermission(
            Permission::ADMIN_USERS,
            $user->warehouse_id,
            Notification::PASSWORD_RESET_REQUESTED,
            $judul,
            $isi,
            $tautan,
            $user,
        );

        $this->kirimEmailKeAdmin($user, $tautan);

        Activity::record(
            ActivityLog::PASSWORD_RESET_REQUEST,
            sprintf('Permintaan lupa sandi untuk akun %s (%s).', $user->full_name, $user->email),
            $user,
            $user->warehouse_id,
            // IP disalin dari permintaan aslinya: di dalam antrean
            // Request::ip() kosong, padahal justru IP-lah yang membedakan
            // pemiliknya dari orang yang mencoba mengambil alih akunnya.
            ['ip' => $this->ip],
        );
    }

    /**
     * Email sebagai cadangan lonceng: admin tidak selalu sedang membuka
     * sistem saat seseorang terkunci di luarnya.
     */
    private function kirimEmailKeAdmin(User $user, string $tautan): void
    {
        $penerima = User::query()
            ->where('is_active', true)
            ->whereNotNull('email')
            ->whereHas('role', fn ($q) => $q->whereIn('slug', Permission::MATRIX[Permission::ADMIN_USERS] ?? []))
            ->when($user->warehouse_id !== null, fn ($q) => $q->where(
                fn ($w) => $w->where('warehouse_id', $user->warehouse_id)->orWhereNull('warehouse_id')
            ))
            ->whereKeyNot($user->id)
            ->pluck('email')
            ->filter()
            ->all();

        if ($penerima === []) {
            return;
        }

        Mail::to($penerima)->queue(new PermintaanLupaSandi($user, $tautan));
    }
}
