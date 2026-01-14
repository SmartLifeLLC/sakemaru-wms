<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintRequestQueue extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'print_request_queue';

    protected $fillable = [
        'client_id',
        'earning_ids',
        'print_type',
        'group_by_delivery_course',
        'warehouse_id',
        'printer_driver_id',
        'status',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'earning_ids' => 'array',
        'group_by_delivery_course' => 'boolean',
        'processed_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    // Print type constants
    public const PRINT_TYPE_CLIENT_SLIP = 'CLIENT_SLIP';

    public const PRINT_TYPE_CLIENT_SLIP_PRINTER = 'CLIENT_SLIP_PRINTER';
}
