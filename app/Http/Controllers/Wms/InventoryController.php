<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    /**
     * F-INV-01: Tampilan Stok
     */
    public function index(Request $request)
    {
        // Dummy data according to PRD:
        // no, SKU, Deskripsi Produk, Batch No, uom, Lokasi Rak, Qty Tersedia, Qty Teralokasi, Tanggal Produksi, Gudang.
        $inventories = [
            [
                'id' => 1,
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch_no' => 'BCH-202608-01',
                'uom' => '5Kg',
                'location' => 'G-03-01',
                'qty_available' => 180,
                'qty_allocated' => 0,
                'production_date' => '18 Aug 2026',
                'warehouse' => 'Gudang Utama'
            ],
            [
                'id' => 2,
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch_no' => 'BCH-202607-15',
                'uom' => '5Kg',
                'location' => 'G-03-02',
                'qty_available' => 50,
                'qty_allocated' => 20,
                'production_date' => '15 Jul 2026',
                'warehouse' => 'Gudang Utama'
            ],
            [
                'id' => 3,
                'sku' => 'BP-20KG-BLU',
                'description' => 'Cat Pelapis Berger Blue 20Kg',
                'batch_no' => 'BCH-202608-05',
                'uom' => '20Kg',
                'location' => 'G-01-10',
                'qty_available' => 45,
                'qty_allocated' => 45,
                'production_date' => '05 Aug 2026',
                'warehouse' => 'Gudang Utama'
            ],
            [
                'id' => 4,
                'sku' => 'BP-1KG-RED',
                'description' => 'Cat Minyak Berger Red 1Kg',
                'batch_no' => 'BCH-202606-20',
                'uom' => '1Kg',
                'location' => 'G-05-01',
                'qty_available' => 1200,
                'qty_allocated' => 300,
                'production_date' => '20 Jun 2026',
                'warehouse' => 'Gudang Tambahan'
            ],
        ];

        return view('wms.inventory.index', compact('inventories'));
    }
}