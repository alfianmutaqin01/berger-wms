<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;

class InventoryController extends Controller
{
    private function getRawInventories()
    {
        if (session()->has('raw_inventories')) {
            return session('raw_inventories');
        }

        $rawInventories = \App\Data\MockInventory::getRawInventories();
        session(['raw_inventories' => $rawInventories]);
        return $rawInventories;
    }

    public function index(Request $request)
    {
        $now = Carbon::now();
        $rawInventories = $this->getRawInventories();
        $inventories = [];

        foreach ($rawInventories as $sku => $data) {
            $goodQty = 0;
            $goodAlloc = 0;
            $ddpQty = 0;
            
            $goodBatches = [];
            $ddpBatches = [];

            foreach ($data['batches'] as $batch) {
                $mfgDate = Carbon::parse($batch['mfg_date']);
                $expDate = $mfgDate->copy()->addMonths(30);
                $isExpired = $now->greaterThan($expDate);
                $isDamaged = $batch['is_damaged'] ?? false;
                
                $batchData = $batch;
                $batchData['exp_date'] = $expDate->format('Y-m-d');
                $batchData['is_expired'] = $isExpired;

                $batchTotalQty = collect($batch['pallets'])->sum('qty');
                $batchTotalAlloc = collect($batch['pallets'])->sum('alloc');
                $batchData['total_qty'] = $batchTotalQty;
                
                if ($isExpired || $isDamaged) {
                    $ddpBatches[] = $batchData;
                    $ddpQty += $batchTotalQty;
                } else {
                    $goodBatches[] = $batchData;
                    $goodQty += $batchTotalQty;
                    $goodAlloc += $batchTotalAlloc;
                }
            }

            $inventories[$sku] = [
                'name' => $data['name'],
                'uom' => $data['uom'],
                'good_qty' => $goodQty,
                'good_alloc' => $goodAlloc,
                'ddp_qty' => $ddpQty,
                'good_batches' => $goodBatches,
                'ddp_batches' => $ddpBatches
            ];
        }

        return view('wms.inventory.index', compact('inventories'));
    }

    public function adjust(Request $request)
    {
        $sku = $request->sku;
        $action = $request->action;
        $qty = (int)$request->qty;
        
        $raw = $this->getRawInventories();
        if (isset($raw[$sku])) {
            // Kita sederhanakan: Adjust ke pallet pertama yang ditemukan
            if (isset($raw[$sku]['batches'][0]['pallets'][0])) {
                if ($action == 'add') {
                    $raw[$sku]['batches'][0]['pallets'][0]['qty'] += $qty;
                } elseif ($action == 'deduct') {
                    $raw[$sku]['batches'][0]['pallets'][0]['qty'] = max(0, $raw[$sku]['batches'][0]['pallets'][0]['qty'] - $qty);
                } elseif ($action == 'writeoff') {
                    // deduct from good stock and create a damaged batch
                    $raw[$sku]['batches'][0]['pallets'][0]['qty'] = max(0, $raw[$sku]['batches'][0]['pallets'][0]['qty'] - $qty);
                    $raw[$sku]['batches'][] = [
                        'batch_no' => 'BCH-WO-' . rand(100, 999),
                        'mfg_date' => now()->format('Y-m-d'),
                        'is_damaged' => true,
                        'pallets' => [
                            ['pallet_no' => 'PLT-DDP-NEW', 'location' => 'Rak DDP-1', 'qty' => $qty, 'alloc' => 0]
                        ]
                    ];
                }
            }
            session(['raw_inventories' => $raw]);
        }

        return redirect()->back()->with('success', 'Penyesuaian stok berhasil dicatat ke dalam sistem.');
    }

    public function transfer(Request $request)
    {
        $sku = $request->sku;
        $fromLoc = $request->from_loc;
        $toLoc = $request->to_loc;
        $qty = (int)$request->qty;

        $raw = $this->getRawInventories();
        if (isset($raw[$sku])) {
            $deducted = 0;
            // Kurangi dari lokasi awal
            foreach ($raw[$sku]['batches'] as &$batch) {
                foreach ($batch['pallets'] as &$pallet) {
                    if ($pallet['location'] == $fromLoc && $pallet['qty'] > 0 && $deducted < $qty) {
                        $canDeduct = min($pallet['qty'], $qty - $deducted);
                        $pallet['qty'] -= $canDeduct;
                        $deducted += $canDeduct;
                    }
                }
            }

            // Tambah ke lokasi tujuan (buat pallet baru di batch pertama)
            if ($deducted > 0) {
                $raw[$sku]['batches'][0]['pallets'][] = [
                    'pallet_no' => 'PLT-TRF-' . rand(100, 999),
                    'location' => $toLoc,
                    'qty' => $deducted,
                    'alloc' => 0
                ];
            }
            session(['raw_inventories' => $raw]);
        }

        return redirect()->back()->with('success', 'Perpindahan lokasi/pallet stok berhasil diproses.');
    }
}