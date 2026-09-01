<?php

namespace Database\Seeders;

use App\Models\PaymentTerm;
use Illuminate\Database\Seeder;

/**
 * Syarat pembayaran — mengikuti pilihan yang sudah ada di form Buat Pesanan
 * Sales (resources/views/sales/new_order.blade.php).
 *
 * `credit_limit` sengaja dibiarkan NULL: plafon per termin belum ditetapkan,
 * dan sistem belum punya proses pembayaran apa pun. Diisi Manager saat modul
 * Billing (Fase 8) dikerjakan.
 */
class PaymentTermSeeder extends Seeder
{
    public function run(): void
    {
        $terms = [
            ['code' => 'cash', 'name' => 'Cash / Tunai', 'days' => 0, 'sort_order' => 1],
            ['code' => 'transfer', 'name' => 'Transfer Bank', 'days' => 0, 'sort_order' => 2],
            ['code' => 'tempo_30', 'name' => 'Tempo 30 Hari', 'days' => 30, 'sort_order' => 3],
            ['code' => 'tempo_60', 'name' => 'Tempo 60 Hari', 'days' => 60, 'sort_order' => 4],
            ['code' => 'tempo_90', 'name' => 'Tempo 90 Hari', 'days' => 90, 'sort_order' => 5],
        ];

        foreach ($terms as $term) {
            PaymentTerm::firstOrCreate(
                ['code' => $term['code']],
                $term + ['credit_limit' => null, 'is_active' => true]
            );
        }
    }
}
