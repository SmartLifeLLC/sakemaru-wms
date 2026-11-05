<?php

return [
    'client_id' => 6,
    'process_date' => '2026-09-30',
    'category1_code' => '1004',
    'approval_key' => 'submitted-2026-09-unmanaged-41-47',
    // Bind to the submitted count, not merely the warehouse (cheese counts coexist).
    'counts' => [
        14 => ['warehouse_code' => '1', 'amount' => 1201],
        15 => ['warehouse_code' => '2', 'amount' => 16938],
        18 => ['warehouse_code' => '4', 'amount' => -11947],
        20 => ['warehouse_code' => '7', 'amount' => -10122],
        19 => ['warehouse_code' => '8', 'amount' => 9211],
        21 => ['warehouse_code' => '9', 'amount' => -6388],
        22 => ['warehouse_code' => '10', 'amount' => -20030],
        23 => ['warehouse_code' => '11', 'amount' => -178904],
        24 => ['warehouse_code' => '21', 'amount' => -94735],
        25 => ['warehouse_code' => '22', 'amount' => -103383],
        29 => ['warehouse_code' => '71', 'amount' => -6357192],
        26 => ['warehouse_code' => '91', 'amount' => -31845, 'includes_warehouse_codes' => ['91', '92']],
        27 => ['warehouse_code' => '98', 'amount' => -210],
    ],
];
