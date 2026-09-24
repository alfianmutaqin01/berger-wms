<?php

namespace App\Console\Commands;

use App\Models\InventoryStock;
use App\Models\Notification;
use App\Support\Notifier;
use App\Support\Permission;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Mengabari Logistik tentang stok DDP yang masih berdiri di rak barang bagus.
 *
 * KENAPA SATU PENGABAR BERKALA, BUKAN PANGGILAN DI TIAP SUMBER DDP. Stok bisa
 * jatuh ke DDP dari empat tempat berbeda — sweep kedaluwarsa tiap malam,
 * koreksi/write-off, retur rusak yang masuk lewat put-away, dan penolakan
 * pelanggan — dan besok bisa bertambah satu lagi. Menyisipkan pemberitahuan di
 * masing-masing berarti sumber kelima nanti akan lupa disisipi, dan yang
 * terjadi bukan galat melainkan diam: barang kedaluwarsa tetap di rak FG tanpa
 * ada yang diberi tahu. Satu pemeriksa yang membaca keadaan nyata tidak bisa
 * melewatkan sumber mana pun, sekarang maupun nanti.
 *
 * SEKALI SEHARI SUDAH CUKUP. Masa simpan dihitung per HARI, jadi daftar barang
 * kedaluwarsa hanya berubah sekali sehari; perintah ini dijadwalkan menyusul
 * sweep kedaluwarsa, bukan diulang tiap beberapa menit. Stok yang jatuh ke DDP
 * dari jalur lain di tengah hari tetap langsung tampil di layar Pemindahan DDP
 * — yang menunggu putaran berikutnya hanyalah dentang loncengnya.
 *
 * SATU NOTIFIKASI PER GUDANG PER PUTARAN, bukan satu per baris. Enam puluh
 * batch kedaluwarsa dalam semalam akan menenggelamkan lonceng dan justru
 * menyembunyikan kabar lain yang juga harus dibaca hari itu.
 */
class KabarkanStokDdp extends Command
{
    protected $signature = 'stock:kabarkan-ddp {--dry-run : Tampilkan yang akan dikabarkan tanpa mengirim}';

    protected $description = 'Mengabari Logistik tentang stok DDP yang belum turun ke rak DDP';

    public function handle(): int
    {
        $baru = InventoryStock::menungguRakDdp()
            ->whereNull('ddp_notified_at')
            ->with('product:id,sku')
            ->get();

        if ($baru->isEmpty()) {
            $this->info('Tidak ada stok DDP baru yang perlu dikabarkan.');

            return self::SUCCESS;
        }

        foreach ($baru->groupBy('warehouse_id') as $warehouseId => $barisGudang) {
            $pesan = $this->pesan($barisGudang);

            if ($this->option('dry-run')) {
                $this->warn(sprintf('Gudang %s: %s', $warehouseId, $pesan));

                continue;
            }

            Notifier::toPermission(
                Permission::INVENTORY_DDP_ASSIGN,
                (int) $warehouseId,
                Notification::STOCK_DDP_PENDING,
                'Stok DDP menunggu dipindah ke rak DDP',
                $pesan,
                route('wms.ddp.index'),
            );

            /*
             * DITANDAI SESUDAH KABARNYA TERKIRIM. Kalau ditandai lebih dulu lalu
             * pengiriman gagal, barisnya terhitung sudah dikabarkan selamanya
             * dan tidak ada yang pernah tahu — kegagalan yang paling mahal
             * justru yang tidak meninggalkan jejak apa pun.
             */
            InventoryStock::whereIn('id', $barisGudang->pluck('id'))
                ->update(['ddp_notified_at' => now()]);
        }

        $this->info($baru->count().' baris stok DDP dikabarkan ke Logistik.');

        return self::SUCCESS;
    }

    /**
     * Isi kabarnya: berapa batch, berapa unit, dan SKU apa saja.
     *
     * SKU DISEBUT, bukan cuma jumlahnya. "3 batch menunggu" memaksa orang
     * membuka layar untuk tahu apakah kabar itu mendesak; menyebut SKU-nya
     * membuat keputusan itu bisa diambil dari lonceng.
     *
     * @param  Collection<int, InventoryStock>  $baris
     */
    private function pesan(Collection $baris): string
    {
        $sku = $baris->pluck('product.sku')->filter()->unique()->values();

        return sprintf(
            '%d batch (%s unit) jatuh ke DDP dan masih di rak barang bagus: %s. Tentukan mana yang turun ke rak DDP hari ini.',
            $baris->count(),
            number_format((int) $baris->sum('qty_available')),
            $sku->count() > 3
                ? $sku->take(3)->implode(', ').' dan '.($sku->count() - 3).' SKU lain'
                : $sku->implode(', '),
        );
    }
}
