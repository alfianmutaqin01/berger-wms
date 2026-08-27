<?php

namespace App\Data;

class MockInventory
{
    public static function getRawInventories()
    {
        return [
            'ID1-F13150216210' => [
                'name' => 'AMC Fast Green 1Ltr',
                'uom' => 'CAN',
                'batches' => [
                    [
                        'batch_no' => 'BCH-2608-001',
                        'mfg_date' => '2026-08-01',
                        'pallets' => [
                            ['pallet_no' => 'PLT-001', 'location' => 'Rak A', 'qty' => 150, 'alloc' => 50],
                            ['pallet_no' => 'PLT-002', 'location' => 'Rak B', 'qty' => 150, 'alloc' => 0],
                        ],
                    ],
                    [
                        'batch_no' => 'BCH-2301-999',
                        'mfg_date' => '2023-01-15',
                        'pallets' => [
                            ['pallet_no' => 'PLT-003', 'location' => 'Rak DDP-1', 'qty' => 20, 'alloc' => 0],
                        ],
                    ],
                ],
            ],
            'ID1-F00123202225' => [
                'name' => 'Apex Emulsion White 2.5Ltr',
                'uom' => 'TIN',
                'batches' => [
                    [
                        'batch_no' => 'BCH-2607-042',
                        'mfg_date' => '2026-07-15',
                        'pallets' => [
                            ['pallet_no' => 'PLT-015', 'location' => 'Rak G', 'qty' => 250, 'alloc' => 100],
                        ],
                    ],
                ],
            ],
            'ID1-F00123708320' => [
                'name' => 'Apex Emulsion Harvest Cream 20Ltr',
                'uom' => 'PAIL',
                'batches' => [
                    [
                        'batch_no' => 'BCH-2608-010',
                        'mfg_date' => '2026-08-20',
                        'pallets' => [
                            ['pallet_no' => 'PLT-102', 'location' => 'Rak C', 'qty' => 80, 'alloc' => 0],
                        ],
                    ],
                    [
                        'batch_no' => 'BCH-2608-010',
                        'mfg_date' => '2026-08-20',
                        'is_damaged' => true,
                        'pallets' => [
                            ['pallet_no' => 'PLT-RET-1', 'location' => 'Rak DDP-2', 'qty' => 2, 'alloc' => 0],
                        ],
                    ],
                ],
            ],
        ];
    }
}
