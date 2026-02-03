<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCollaborationData extends CustomModel
{
    use HasFactory;

    protected $casts = [];

    public function user(): belongsTo
    {
        return $this->belongsTo(User::class);
    }
}
