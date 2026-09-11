<?php

namespace App\Console\Commands;

use App\Models\InventoryStock;
use App\Support\Inventory\StockQuarantine;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Melepas penanda "Dahulukan Keluar" dari batch yang isinya sudah habis.
 *
 * Permintaan pemilik produk: penandanya berlaku SAMPAI BATCHNYA HABIS, bukan
 * sampai seseorang ingat mematikannya. Alasan memakainya hampir selalu "batch
 * ini harus dikosongkan duluan" — begitu kosong, urusannya selesai.
 *
 * HABIS = qty_available + qty_allocated NOL DI SELURUH BARIS BATCH. Batch yang
 * qty_available-nya nol tetapi masih punya qty_allocated BELUM habis: barangnya
 * masih berdiri di rak, sudah dijanjikan tetapi belum turun. Melepas penandanya
 * di titik itu salah — kalau alokasinya kemudian dibatalkan, batch yang
 * seharusnya didahulukan kembali ke antrean belakang tanpa ada yang tahu.
 *
 * SWEEP, BUKAN DIPICU SAAT PENGAMBILAN. Qty bisa mencapai nol lewat banyak
 * jalur (kirim, koreksi, transfer, stocktake); menempelkan pelepasan di
 * masing-masing berarti lima salinan aturan yang sama dan satu yang terlupa.
 * Lagi pula penanda yang tertinggal pada batch kosong TIDAK berbahaya — batch
 * kosong tidak pernah ikut dicalonkan keluar. Jadi keterlambatan sehari tidak
 * menimbulkan akibat apa pun, dan itulah yang membuat sweep pilihan yang tepat
 * di sini.
 *
 * Dijalankan harian 00:15 WIB, diselisihkan 5 menit dari sweep karantina.
 */
class SweepPriority extends Command
{
    protected $signature = 'stock:sweep-priority {--dry-run : Tampilkan yang akan dilepas tanpa menyimpan}';

    protected $description = 'Melepas penanda Dahulukan Keluar dari batch yang isinya sudah habis';

    public function __construct(private readonly StockQuarantine $penanda)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $habis = InventoryStock::with('product:id,sku')
            ->where('prioritize_out', true)
            ->get()
            // Dikelompokkan per BATCH, bukan per baris: penandanya melekat pada
            // batch, jadi yang menentukan "habis" pun harus seluruh batch.
            ->groupBy(fn (InventoryStock $s) => $s->product_id.'|'.$s->warehouse_id.'|'.$s->batch_no)
            ->filter(fn ($baris) => $baris->sum(
                fn (InventoryStock $s) => (int) $s->qty_available + (int) $s->qty_allocated
            ) === 0);

        if ($habis->isEmpty()) {
            $this->info('Tidak ada batch berpenanda Dahulukan Keluar yang sudah habis.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn($habis->count().' batch AKAN dilepas dari penanda Dahulukan Keluar:');

            foreach ($habis as $baris) {
                $this->line(sprintf(
                    '  %s batch %s — %d baris rak, semuanya kosong',
                    $baris->first()->product?->sku ?? '—',
                    $baris->first()->batch_no ?? '—',
                    $baris->count(),
                ));
            }

            return self::SUCCESS;
        }

        $jumlah = 0;

        foreach ($habis as $baris) {
            try {
                // user_id null: ini tindakan sistem, bukan keputusan orang.
                $jumlah += $this->penanda->releasePriority($baris->first(), null, olehSistem: true);
            } catch (RuntimeException $e) {
                // Balapan dengan pelepasan manual di layar. Bukan galat —
                // hasil akhirnya justru sudah seperti yang diinginkan.
                $this->warn(sprintf('Batch %s dilewati: %s', $baris->first()->batch_no ?? '—', $e->getMessage()));
            }
        }

        $this->info($jumlah.' baris stok dilepas dari penanda Dahulukan Keluar karena batchnya habis.');

        return self::SUCCESS;
    }
}
