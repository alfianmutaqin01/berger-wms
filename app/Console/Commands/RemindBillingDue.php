<?php

namespace App\Console\Commands;

use App\Models\CustomerBilling;
use App\Models\Notification;
use App\Support\Billing\Piutang;
use App\Support\Notifier;
use App\Support\Permission;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pengingat piutang harian untuk Manager (Fase 8).
 *
 * PENERIMANYA MANAGER SAJA — keputusan pemilik produk. Bukan Sales, bukan
 * Logistik; dan lewat lonceng web, bukan email atau WhatsApp.
 *
 * SATU LONCENG PER GUDANG PER JENIS, bukan satu per invoice. Sepuluh invoice
 * yang jatuh tempo pada minggu yang sama berarti sepuluh lonceng berbunyi
 * bersamaan, dan yang terbaca hanya yang paling atas. Satu ringkasan yang
 * menyebut jumlah dan nomornya lebih berguna daripada sepuluh yang sama.
 *
 * SETIAP INVOICE DIINGATKAN SEKALI PER TAHAP (segera jatuh tempo, lalu lewat
 * jatuh tempo). Penanda reminded_*_at mencegah lonceng yang sama berbunyi
 * setiap pagi untuk invoice yang itu-itu juga — setelah hari kedua, orang
 * berhenti membukanya. Invoice yang terus menunggak tetap terlihat di tab
 * "Lewat jatuh tempo" dan sebagai penanda ⚠ Menunggak pada customer.
 *
 * Sekaligus menambal tagihan yang terlewat (Piutang::sinkron) — termasuk
 * pesanan tempo yang sudah selesai sebelum modul ini dipasang.
 */
class RemindBillingDue extends Command
{
    protected $signature = 'billing:ingatkan';

    protected $description = 'Membuat tagihan yang terlewat dan mengingatkan Manager atas invoice yang segera/lewat jatuh tempo';

    public function handle(Piutang $piutang): int
    {
        $dibuat = $piutang->sinkron();

        if ($dibuat > 0) {
            $this->info("{$dibuat} tagihan yang terlewat dibuat.");
        }

        $segera = $this->ingatkan(
            CustomerBilling::query()->jatuhTempoDalam(CustomerBilling::HARI_PENGINGAT)->whereNull('reminded_due_soon_at'),
            'reminded_due_soon_at',
            Notification::BILLING_DUE_SOON,
            fn (int $n) => "{$n} invoice segera jatuh tempo",
            fn (string $daftar) => 'Jatuh tempo dalam '.CustomerBilling::HARI_PENGINGAT." hari: {$daftar}.",
            'segera',
        );

        $lewat = $this->ingatkan(
            CustomerBilling::query()->lewatJatuhTempo()->whereNull('reminded_overdue_at'),
            'reminded_overdue_at',
            Notification::BILLING_OVERDUE,
            fn (int $n) => "{$n} invoice lewat jatuh tempo",
            fn (string $daftar) => "Belum dikonfirmasi lunas: {$daftar}.",
            'lewat',
        );

        $this->info("Pengingat dikirim: {$segera} segera jatuh tempo, {$lewat} lewat jatuh tempo.");

        return self::SUCCESS;
    }

    /**
     * @param  callable(int):string  $judul
     * @param  callable(string):string  $isi
     * @return int jumlah invoice yang diingatkan
     */
    private function ingatkan(Builder $query, string $penanda, string $jenis, callable $judul, callable $isi, string $tab): int
    {
        $tagihan = $query
            ->with(['salesOrder:id,order_number,bc_so_number', 'customer:id,name'])
            ->orderBy('due_date')
            ->get();

        $tagihan->groupBy('warehouse_id')->each(function (Collection $perGudang, $gudangId) use ($penanda, $jenis, $judul, $isi, $tab) {
            Notifier::toPermission(
                Permission::BILLING_REMINDER,
                (int) $gudangId,
                $jenis,
                $judul($perGudang->count()),
                $isi($this->daftar($perGudang)),
                route('wms.billing.index', ['tab' => $tab]),
            );

            // Ditandai walau tidak ada Manager yang menerima: gudang tanpa
            // Manager tidak boleh membuat invoice yang sama dicoba setiap pagi
            // selamanya. Penandanya hanya soal lonceng; daftarnya tetap ada.
            CustomerBilling::whereIn('id', $perGudang->pluck('id'))->update([$penanda => now()]);
        });

        return $tagihan->count();
    }

    /** "SO260903 (Toko Maju, 14 Okt), … dan 3 lainnya" — lonceng bukan tempat daftar panjang. */
    private function daftar(Collection $tagihan): string
    {
        $tampil = $tagihan->take(5)->map(fn (CustomerBilling $t) => sprintf(
            '%s (%s, %s)',
            $t->salesOrder?->bc_so_number ?: ($t->salesOrder?->order_number ?? '#'.$t->id),
            $t->customer?->name ?? 'customer',
            $t->due_date->translatedFormat('d M'),
        ))->join(', ');

        $sisa = $tagihan->count() - 5;

        return $sisa > 0 ? "{$tampil}, dan {$sisa} lainnya" : $tampil;
    }
}
