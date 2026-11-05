<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\MorphMany;

class LogPdfExport extends CustomModel
{
    protected bool $hasIsActiveColumn = false;

    protected $guarded = [];

    public function trades(): MorphMany
    {
        return $this->morphMany(Trade::class, 'model');
    }
}
