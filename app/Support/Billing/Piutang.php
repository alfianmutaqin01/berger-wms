<?php

namespace App\Support\Billing;

use App\Models\BillingPayment;
use App\Models\CustomerBilling;
use App\Models\SalesOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seluruh perubahan pada buku pantau piutang lewat sini.
 *
 * Status pesanan dan status tagihan harus selalu sepakat:
 *
 *   tagihan belum lunas  <->  pesanan tempo berstatus COMPLETED_BILLING
 *   tagihan lunas        <->  pesanan berstatus COMPLETED
 *
 * Menulis salah satunya tanpa yang lain membuat Billing dan Dashboard Sales
 * memberi jawaban berbeda untuk pertanyaan yang sama, dan tidak ada yang tahu
 * mana yang benar. Karena itu keduanya hanya diubah bersama, di sini.
 */
class Piutang
{
    /**
     * Mencatat tagihan untuk pesanan tempo yang baru selesai.
     *
     * DIPANGGIL DI DALAM transaksi ProofOfDelivery::complete, supaya pesanan
     * tidak pernah berstatus "menunggu bayar" tanpa tagihan yang bisa dilunasi.
     *
     * Aman dipanggil berulang (pengiriman ulang atas kekurangan membuat pesanan
     * selesai lebih dari sekali): tagihan yang sudah ada TIDAK diubah jatuh
     * temponya. Invoice untuk barang yang sudah sampai sudah berjalan; putaran
     * susulan tidak menggeser kewajiban customer atas putaran pertama.
     */
    public function catat(SalesOrder $order): ?CustomerBilling
    {
        if ($order->status !== SalesOrder::STATUS_COMPLETED_BILLING) {
            return null;
        }

        $indukId = $order->so_merged_into_id ?? $order->id;

        $tagihan = CustomerBilling::query()->lockForUpdate()->where('sales_order_id', $indukId)->first();

        if ($tagihan === null) {
            $order->loadMissing('paymentTerm');

            $sampai = CarbonImmutable::parse($order->delivered_at ?? $order->completed_at ?? now())
                ->timezone(config('wms.timezone', 'Asia/Jakarta'))
                ->startOfDay();

            $hari = (int) ($order->paymentTerm?->days ?? 0);

            $tagihan = CustomerBilling::create([
                'sales_order_id' => $indukId,
                'customer_id' => $order->customer_id,
                'warehouse_id' => $order->warehouse_id,
                'payment_term_id' => $order->payment_term_id,
                'term_days' => $hari,
                'delivered_on' => $sampai->toDateString(),
                'due_date' => $sampai->addDays($hari)->toDateString(),
            ]);
        }

        /*
         * INVOICE-NYA SUDAH LUNAS. Terjadi pada pesanan anak gabungan yang
         * selesai belakangan, atau putaran susulan atas pesanan yang sudah
         * dibayar. Tagihannya tidak dibuka lagi — tanpa nominal, sistem tidak
         * bisa tahu apakah pembayaran itu sudah mencakup barang susulan; yang
         * memutuskannya tetap BC.
         */
        if ($tagihan->sudahLunas()) {
            $order->forceFill(['status' => SalesOrder::STATUS_COMPLETED])->save();
        }

        return $tagihan;
    }

    /**
     * Menandai beberapa tagihan lunas dengan SATU konfirmasi.
     *
     * @param  list<int>  $tagihanId
     * @param  array{paid_on:string, method:string, reference:?string, notes:?string}  $data
     * @param  callable(CustomerBilling):bool  $boleh  pagar gudang dari pemanggil
     *
     * @throws RuntimeException
     */
    public function lunasi(array $tagihanId, array $data, int $userId, callable $boleh): BillingPayment
    {
        return DB::transaction(function () use ($tagihanId, $data, $userId, $boleh) {
            $tagihan = CustomerBilling::query()
                ->whereIn('id', array_values(array_unique($tagihanId)))
                ->with('salesOrder:id,bc_so_number,order_number')
                ->lockForUpdate()
                ->get();

            if ($tagihan->isEmpty() || $tagihan->count() !== count(array_unique($tagihanId))) {
                throw new RuntimeException('Sebagian tagihan yang dipilih tidak ditemukan. Muat ulang halaman lalu pilih lagi.');
            }

            foreach ($tagihan as $t) {
                if (! $boleh($t)) {
                    throw new RuntimeException('Ada tagihan dari gudang lain di pilihan Anda.');
                }
            }

            $sudah = $tagihan->filter->sudahLunas();

            if ($sudah->isNotEmpty()) {
                // Diperiksa DI DALAM kunci: dua Logistik yang membuka layar
                // yang sama bisa sama-sama menekan Lunas.
                throw new RuntimeException(sprintf(
                    'Tagihan %s sudah dilunasi sebelumnya.',
                    $sudah->map(fn (CustomerBilling $t) => $this->nomor($t))->join(', '),
                ));
            }

            /*
             * SATU CUSTOMER PER KONFIRMASI. Satu bukti transfer datang dari
             * satu customer; mencampur dua customer dalam satu konfirmasi
             * hampir pasti salah centang, dan membatalkannya nanti ikut
             * membatalkan tagihan customer lain yang memang sudah bayar.
             */
            if ($tagihan->pluck('customer_id')->unique()->count() > 1) {
                throw new RuntimeException('Satu konfirmasi pelunasan hanya untuk satu customer. Pisahkan pilihan per customer.');
            }

            $pembayaran = BillingPayment::create([
                'customer_id' => $tagihan->first()->customer_id,
                'paid_on' => $data['paid_on'],
                'method' => $data['method'],
                'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'confirmed_by' => $userId,
            ]);

            CustomerBilling::whereIn('id', $tagihan->pluck('id'))->update(['billing_payment_id' => $pembayaran->id]);

            $this->pesananDalam($tagihan)
                ->where('status', SalesOrder::STATUS_COMPLETED_BILLING)
                ->update(['status' => SalesOrder::STATUS_COMPLETED, 'updated_at' => now()]);

            return $pembayaran->setRelation('billings', $tagihan);
        });
    }

    /**
     * Membatalkan konfirmasi lunas yang keliru.
     *
     * Pembayarannya TIDAK dihapus: yang membatalkan dan alasannya tetap
     * terbaca. Tagihannya kembali belum lunas, pesanannya kembali menunggu
     * bayar.
     *
     * @throws RuntimeException
     */
    public function batalkan(BillingPayment $pembayaran, string $alasan, int $userId): int
    {
        return DB::transaction(function () use ($pembayaran, $alasan, $userId) {
            $terkunci = BillingPayment::query()->lockForUpdate()->findOrFail($pembayaran->id);

            if ($terkunci->voided_at !== null) {
                throw new RuntimeException('Konfirmasi pelunasan ini sudah dibatalkan sebelumnya.');
            }

            $tagihan = CustomerBilling::query()
                ->where('billing_payment_id', $terkunci->id)
                ->lockForUpdate()
                ->get();

            $terkunci->forceFill([
                'voided_at' => now(),
                'voided_by' => $userId,
                'void_reason' => trim($alasan),
            ])->save();

            CustomerBilling::whereIn('id', $tagihan->pluck('id'))->update(['billing_payment_id' => null]);

            // Hanya pesanan TEMPO yang dikembalikan. Pesanan tunai yang
            // kebetulan menumpang di invoice gabungan memang selesai sejak
            // awal dan tidak pernah menunggu bayar.
            $this->pesananDalam($tagihan)
                ->where('status', SalesOrder::STATUS_COMPLETED)
                ->whereHas('paymentTerm', fn ($q) => $q->where('days', '>', 0))
                ->update(['status' => SalesOrder::STATUS_COMPLETED_BILLING, 'updated_at' => now()]);

            return $tagihan->count();
        });
    }

    /**
     * Membuat tagihan yang terlewat untuk pesanan tempo yang sudah selesai.
     *
     * Dua sumber: pesanan yang selesai SEBELUM modul ini ada, dan tagihan yang
     * gagal tercatat karena apa pun. Dijalankan penjadwal harian, jadi
     * kekurangan seperti itu tidak bertahan lebih dari sehari.
     */
    public function sinkron(): int
    {
        $dibuat = 0;

        SalesOrder::query()
            ->where('status', SalesOrder::STATUS_COMPLETED_BILLING)
            ->whereNull('cancelled_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('customer_billings')
                ->whereRaw('customer_billings.sales_order_id = COALESCE(sales_orders.so_merged_into_id, sales_orders.id)'))
            ->orderBy('id')
            ->each(function (SalesOrder $order) use (&$dibuat) {
                DB::transaction(function () use ($order, &$dibuat) {
                    $ada = CustomerBilling::where('sales_order_id', $order->so_merged_into_id ?? $order->id)->exists();

                    $this->catat($order);

                    $dibuat += $ada ? 0 : 1;
                });
            });

        return $dibuat;
    }

    /** Nomor yang dikenali Logistik: SO BC, bukan nomor internal. */
    public function nomor(CustomerBilling $tagihan): string
    {
        return $tagihan->salesOrder?->bc_so_number ?: ($tagihan->salesOrder?->order_number ?? '#'.$tagihan->id);
    }

    /**
     * Pesanan induk DAN anak gabungan dari sekumpulan tagihan.
     *
     * @param  Collection<int, CustomerBilling>  $tagihan
     */
    private function pesananDalam(Collection $tagihan)
    {
        $induk = $tagihan->pluck('sales_order_id')->all();

        return SalesOrder::query()->where(fn ($q) => $q
            ->whereIn('id', $induk)
            ->orWhereIn('so_merged_into_id', $induk));
    }
}
