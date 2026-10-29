<?php

namespace App\Models;

/** Learned event factors: positions => {factor, factor_weekday, years, pieces}. */
class WmsDemandEventProfile extends WmsModel
{
    protected $guarded = [];

    protected $casts = ['positions' => 'array', 'pieces' => 'float', 'instance_count' => 'integer', 'trained_through' => 'date:Y-m-d'];
}
