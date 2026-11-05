<?php

namespace App\Models;

class WmsItemReorderPolicy extends WmsModel
{
    protected $guarded = [];

    protected $casts = ['is_enabled' => 'boolean', 'is_configured' => 'boolean', 'arrival_rule' => 'array', 'calculation_basis' => 'array'];
}
