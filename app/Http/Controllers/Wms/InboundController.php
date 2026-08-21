<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class InboundController extends Controller
{
    /**
     * F-INB-01: Riwayat Input Produksi
     */
    public function historyIndex()
    {
        $dummyHistory = [
            [
                'batch_no' => 'BCH-202608-01',
                'doc_no' => 'PROD-202608-001',
                'date' => '19 Aug 2026',
                'total_pallets' => 3,
                'status' => 'Menunggu Put-away'
            ],
            [
                'batch_no' => 'BCH-202608-00',
                'doc_no' => 'PROD-202608-000',
                'date' => '18 Aug 2026',
                'total_pallets' => 5,
                'status' => 'Selesai'
            ],
        ];

        return view('wms.inbound.history', compact('dummyHistory'));
    }

    /**
     * F-INB-01: Detail Riwayat Input Produksi
     */
    public function historyDetail($doc_no)
    {
        // Mock data detail
        $inbound = [
            'doc_no' => $doc_no,
            'batch_no' => 'BCH-202608-01',
            'date' => '19 Aug 2026',
            'status' => 'Menunggu Put-away',
            'total_pallets' => 3
        ];

        $pallets = [
            [
                'id' => 1,
                'pallet_no' => 'PLT-001',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'uom' => '5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 180,
                'max_cap' => 180
            ],
            [
                'id' => 2,
                'pallet_no' => 'PLT-002',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'uom' => '5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 180,
                'max_cap' => 180
            ],
            [
                'id' => 3,
                'pallet_no' => 'PLT-003',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'uom' => '5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 140,
                'max_cap' => 180
            ],
        ];

        return view('wms.inbound.history-detail', compact('inbound', 'pallets'));
    }

    /**
     * F-INB-01: Form Input Produksi (Upload Excel)
     */
    public function create()
    {
        return view('wms.inbound.create');
    }

    /**
     * F-INB-01: Mock Processing Excel to JSON Preview
     */
    public function previewExcel(Request $request)
    {
        sleep(1); // Simulating processing time

        $dummyPallets = [
            [
                'pallet_no' => 1,
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'uom' => '5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 180,
                'max_cap' => 180
            ],
            [
                'pallet_no' => 2,
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'uom' => '5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 180,
                'max_cap' => 180
            ],
            [
                'pallet_no' => 3,
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'uom' => '5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 140,
                'max_cap' => 180
            ],
        ];

        return response()->json([
            'status' => 'success',
            'message' => 'File berhasil diproses. Sistem memecah Qty 500 menjadi 3 palet.',
            'data' => $dummyPallets
        ]);
    }

    /**
     * F-INB-02: List Put-away
     */
    public function putawayIndex()
    {
        $dummyInbounds = [
            [
                'batch_no' => 'BCH-202608-01',
                'doc_no' => 'PROD-8821',
                'date' => '18 Aug 2026',
                'total_pallets' => 3,
                'status' => 'Menunggu Put-away'
            ],
            [
                'batch_no' => 'BCH-202608-02',
                'doc_no' => 'PROD-8822',
                'date' => '18 Aug 2026',
                'total_pallets' => 5,
                'status' => 'Menunggu Put-away'
            ],
        ];

        return view('wms.inbound.putaway-list', compact('dummyInbounds'));
    }

    /**
     * F-INB-02: Detail Put-away
     */
    public function putawayProcess($doc_no) { $inbound = [ 'doc_no' => $doc_no, 'batch_no' => 'BCH-202608-01',
            'date' => '18 Aug 2026',
        ];

        $pallets = [
            [
                'id' => 1,
                'pallet_no' => 'PLT-001',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 180,
                'location' => ''
            ],
            [
                'id' => 2,
                'pallet_no' => 'PLT-002',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 180,
                'location' => ''
            ],
            [
                'id' => 3,
                'pallet_no' => 'PLT-003',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 140,
                'location' => ''
            ],
        ];

        $availableLocations = ['G-03-01 (Kosong)', 'G-03-02 (Kosong)', 'G-03-03 (Kosong)', 'G-03-04 (Kosong)', 'G-03-05 (Kosong)'];

        return view('wms.inbound.putaway-process', compact('inbound', 'pallets', 'availableLocations'));
    }

    /**
     * F-INB-03: List Verifikasi Logistik
     */
    public function verifyIndex()
    {
        $dummyVerifications = [
            [
                'batch_no' => 'BCH-202608-01',
                'doc_no' => 'PROD-8821',
                'date' => '18 Aug 2026',
                'total_pallets' => 3,
                'status' => 'Menunggu Verifikasi'
            ]
        ];

        return view('wms.inbound.verify-list', compact('dummyVerifications'));
    }

    /**
     * F-INB-03: Detail Verifikasi Logistik
     */
    public function verifyProcess($doc_no) { $inbound = [ 'doc_no' => $doc_no, 'batch_no' => 'BCH-202608-01',
            'date' => '18 Aug 2026',
        ];

        // This simulates pallets that ALREADY have locations set by Operator Gudang
        $pallets = [
            [
                'id' => 1,
                'pallet_no' => 'PLT-001',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 180,
                'location' => 'G-03-01'
            ],
            [
                'id' => 2,
                'pallet_no' => 'PLT-002',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 180,
                'location' => 'G-03-02'
            ],
            [
                'id' => 3,
                'pallet_no' => 'PLT-003',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 140,
                'location' => 'G-03-03'
            ],
        ];

        $availableLocations = ['G-03-01 (Kosong)', 'G-03-02 (Kosong)', 'G-03-03 (Kosong)', 'G-03-04 (Kosong)', 'G-03-05 (Kosong)'];

        return view('wms.inbound.verify-process', compact('inbound', 'pallets', 'availableLocations'));
    }
    public function returnsIndex()
    {
        $pendingReturns = session('pending_returns', []);
        return view('wms.inbound.returns', compact('pendingReturns'));
    }

    public function processReturn($id, \Illuminate\Http\Request $request)
    {
        $pending = session('pending_returns', []);
        $newPending = [];
        foreach ($pending as $retur) {
            if ($retur['id'] !== $id) { $newPending[] = $retur; }
        }
        session(['pending_returns' => $newPending]);
        session()->put('processed_return_' . $id, $request->alokasi);
        return redirect()->back()->with('success', 'Barang retur berhasil dialokasikan ke ' . $request->alokasi . '.');
    }
}