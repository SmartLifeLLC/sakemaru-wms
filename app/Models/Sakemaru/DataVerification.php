<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataVerification extends CustomModel
{
    protected $casts = [];

    protected $guarded = [];

    public function user(): belongsTo
    {
        return $this->belongsTo(User::class, 'creator_id', 'id');
    }
}
