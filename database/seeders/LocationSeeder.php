<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Membangkitkan seluruh 2.264 lokasi bin sesuai denah gudang Berger.
 *
 * Dibangkitkan dari aturan, BUKAN disalin baris per baris: polanya sepenuhnya
 * beraturan, sehingga menyalin ribuan baris hanya menambah peluang salah ketik
 * tanpa memberi ketelitian tambahan. Jumlah hasil bangkitan diperiksa terhadap
 * angka yang diberikan pemilik gudang (lihat assertion di bawah).
 *
 * Kode berpola [Rak]-[Level]-[Sel]; seluruh rak punya 5 level. Perhatikan
 * bahwa pada sebagian besar rak, Level 4–5 memuat LEBIH BANYAK sel daripada
 * Level 1–3 — rak bagian atas lebih panjang karena tidak terpotong jalur lalu
 * lintas forklift di bawahnya.
 *
 * Tidak ada Rak "A" pada denah gudang.
 */
class LocationSeeder extends Seeder
{
    /**
     * [daftar rak, sel pada level 1–3, sel pada level 4–5, zona]
     *
     * @var list<array{0: list<string>, 1: int, 2: int, 3: string}>
     */
    private const LAYOUT = [
        [['B', 'C', 'D', 'E', 'F', 'G'], 11, 13, Location::ZONE_FAST],
        [['H', 'I'], 8, 10, Location::ZONE_FAST],
        [['J', 'K', 'L', 'M', 'N', 'O'], 12, 14, Location::ZONE_FAST],

        [['P'], 20, 20, Location::ZONE_SLOW],
        [['Q', 'R', 'S', 'T'], 18, 20, Location::ZONE_SLOW],

        // Q–V punya jumlah sel yang sama, tetapi zonanya terbelah: Q–T masuk
        // Slow Moving, U–V masuk Middle Moving.
        [['U', 'V'], 18, 20, Location::ZONE_MIDDLE],
        [['W', 'X'], 18, 18, Location::ZONE_MIDDLE],
        [['Y', 'Z', 'ZA', 'ZB', 'ZC', 'ZD'], 19, 21, Location::ZONE_MIDDLE],
    ];

    /** Jumlah yang diharapkan, dari pendataan gudang. */
    private const EXPECTED_TOTAL = 2264;

    private const EXPECTED_PER_ZONE = [
        Location::ZONE_FAST => 826,
        Location::ZONE_SLOW => 476,
        Location::ZONE_MIDDLE => 962,
    ];

    public function run(): void
    {
        $warehouse = Warehouse::orderBy('id')->first();

        if (! $warehouse) {
            $this->command?->warn('LocationSeeder dilewati: belum ada gudang. Jalankan WarehouseSeeder lebih dulu.');

            return;
        }

        if (Location::where('warehouse_id', $warehouse->id)->exists()) {
            $this->command?->info('LocationSeeder dilewati: lokasi untuk gudang ini sudah ada.');

            return;
        }

        $rows = $this->buildRows($warehouse->id);

        $this->assertMatchesFloorPlan($rows);

        // Disisipkan per potongan; 2.264 baris sekaligus melampaui batas
        // jumlah parameter yang nyaman untuk satu pernyataan INSERT.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('locations')->insert($chunk);
        }

        $this->command?->info(sprintf(
            'LocationSeeder: %d lokasi dibuat untuk gudang %s.',
            count($rows),
            $warehouse->code
        ));
    }

    /** @return list<array<string, mixed>> */
    private function buildRows(int $warehouseId): array
    {
        $now = now();
        $rows = [];

        foreach (self::LAYOUT as [$racks, $cellsLower, $cellsUpper, $zone]) {
            foreach ($racks as $rack) {
                for ($level = 1; $level <= Location::MAX_LEVEL; $level++) {
                    $cells = $level <= 3 ? $cellsLower : $cellsUpper;

                    for ($cell = 1; $cell <= $cells; $cell++) {
                        $rows[] = [
                            'warehouse_id' => $warehouseId,
                            'code' => Location::buildCode($rack, $level, $cell),
                            'rack' => $rack,
                            'level' => $level,
                            'cell' => $cell,
                            'zone' => $zone,
                            'is_active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Memastikan hasil bangkitan cocok dengan pendataan gudang.
     *
     * Aturan di LAYOUT mudah salah ketik satu angka tanpa disadari; pemeriksaan
     * ini membuat kekeliruan semacam itu langsung menggagalkan seeder, bukan
     * diam-diam menghasilkan denah yang salah.
     */
    private function assertMatchesFloorPlan(array $rows): void
    {
        $total = count($rows);

        if ($total !== self::EXPECTED_TOTAL) {
            throw new \RuntimeException(
                "LocationSeeder menghasilkan {$total} lokasi, seharusnya ".self::EXPECTED_TOTAL.'. Periksa LAYOUT.'
            );
        }

        $perZone = collect($rows)->countBy('zone');

        foreach (self::EXPECTED_PER_ZONE as $zone => $expected) {
            $actual = $perZone[$zone] ?? 0;

            if ($actual !== $expected) {
                throw new \RuntimeException(
                    "LocationSeeder menghasilkan {$actual} lokasi pada zona {$zone}, seharusnya {$expected}."
                );
            }
        }

        if (count(array_unique(array_column($rows, 'code'))) !== $total) {
            throw new \RuntimeException('LocationSeeder menghasilkan kode lokasi ganda.');
        }
    }
}
