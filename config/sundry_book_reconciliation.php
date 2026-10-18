<?php

return [
    // Customer-approved September inventory settlement. May-August reports stay unchanged.
    // May 1-5 amounts match 雑貨棚卸_確認のお願い_20261004.pdf and Oracle T3 by middle category.
    'client_id' => 6,
    'process_date' => '2026-09-30',
    'reference_date' => '2026-08-31',
    'unmanaged_gaps' => [
        1 => [2041 => 2698, 2047 => 225],
        2 => [2041 => 2850, 2047 => 225],
        3 => [2041 => 6286, 2047 => 470],
        4 => [2041 => 4984, 2047 => 300],
        7 => [2041 => 915, 2047 => 755],
        8 => [2041 => 7680, 2047 => 490],
        9 => [2041 => 1469, 2047 => 245],
        10 => [2041 => 3591, 2047 => 4257],
        11 => [2041 => 6369, 2047 => 830],
        21 => [2041 => 1128, 2047 => 170],
        22 => [2041 => 3920, 2047 => 1555],
        91 => [2041 => 6560, 2047 => 10608],
    ],
    // This is separate from the 68,580 above; the 2,923 for warehouse 1 is NOT added again.
    'managed_gaps' => [1 => [523001 => ['category1_code' => 1005, 'amount' => 28000]]],
];
