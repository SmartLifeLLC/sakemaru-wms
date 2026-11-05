<?php

namespace App\Models;

class WmsAutoOrderRun extends WmsModel
{
    protected $guarded = [];

    protected $casts = ['is_preview' => 'boolean', 'business_date' => 'date', 'finished_at' => 'datetime'];
}
