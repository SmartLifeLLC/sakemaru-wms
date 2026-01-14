<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RebateDeposit extends CustomModel
{
    protected $guarded = [];

    protected $casts = [];

    public function trade(): belongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function supplier(): belongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function account_classification(): belongsTo
    {
        return $this->belongsTo(AccountClassification::class);
    }

    public function rebate_bill(): BelongsTo
    {
        return $this->belongsTo(RebateBill::class);
    }
}
