<?php

namespace App\Models;

/** Product × standard event applicability (年末年始／お盆／GW／任意). Only enabled pairs receive factors and exclusions. */
class WmsItemDemandEvent extends WmsModel
{
    protected $guarded = [];

    protected $casts = ['is_enabled' => 'boolean'];
}
