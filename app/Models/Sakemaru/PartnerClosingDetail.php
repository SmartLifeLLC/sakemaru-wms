<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerClosingDetail extends CustomModel
{
    protected $guarded = [];

    protected $casts = [];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function ledgerClassification(): BelongsTo
    {
        return $this->belongsTo(LedgerClassification::class);
    }
}
