<?php

namespace App\Models;

/** Learned event factors: positions => {factor, factor_weekday, years, pieces}. stage ACTIVE is used; CANDIDATE awaits a passed backtest or an approval. */
class WmsDemandEventProfile extends WmsModel
{
    public const ACTIVE = 'ACTIVE';

    public const CANDIDATE = 'CANDIDATE';

    protected $guarded = [];

    protected $casts = ['positions' => 'array', 'pieces' => 'float', 'instance_count' => 'integer', 'trained_through' => 'date:Y-m-d'];
}
