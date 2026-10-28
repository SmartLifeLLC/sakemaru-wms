<?php

return [
    // Use shared storage in deployed environments; local exports stay on this machine.
    'disk' => env('WAREHOUSE_ORDER_REVIEW_DISK', env('APP_ENV') === 'local' ? 'local' : 's3'),
    'connection' => env('WAREHOUSE_ORDER_REVIEW_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'queue' => env('WAREHOUSE_ORDER_REVIEW_QUEUE', 'default'),
];
