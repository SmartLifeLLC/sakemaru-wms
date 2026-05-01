<?php

namespace App\Models;

use App\Models\Sakemaru\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class WmsModel extends Model
{
    protected $connection = 'sakemaru';

    public function modifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modified_by');
    }

    public function getModifierDisplayNameAttribute(): string
    {
        if ($this->modified_by) {
            return $this->modifiedByUser?->name ?? "ID:{$this->modified_by}";
        }

        return 'システム';
    }
}
