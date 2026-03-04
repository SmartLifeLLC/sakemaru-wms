<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsIncomingReceivedFile extends WmsModel
{
    protected $table = 'wms_incoming_received_files';

    protected $fillable = [
        'contractor_id',
        'filename',
        'format_type',
        'status',
        'a_data_type',
        'a_send_receive_type',
        'a_created_date',
        'a_created_time',
        'a_record_count',
        'a_slip_count',
        'a_company_name',
        'has_finet_wrapper',
        'finet_sender_code',
        'finet_sender_name',
        'finet_record_count',
        'parsed_slip_count',
        'parsed_detail_count',
        'error_message',
        'received_by',
    ];

    protected $casts = [
        'has_finet_wrapper' => 'boolean',
        'a_record_count' => 'integer',
        'a_slip_count' => 'integer',
        'finet_record_count' => 'integer',
        'parsed_slip_count' => 'integer',
        'parsed_detail_count' => 'integer',
    ];

    public function slips(): HasMany
    {
        return $this->hasMany(WmsIncomingReceivedSlip::class, 'received_file_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(WmsIncomingReceivedDetail::class, 'received_file_id');
    }

    public function contractor()
    {
        return $this->belongsTo(\App\Models\Sakemaru\Contractor::class);
    }
}
