<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadTime extends CustomModel
{
    protected $guarded = [];

    protected $casts = [];

    public function contractior(): HasMany
    {
        return $this->hasMany(Contractor::class);
    }
}
