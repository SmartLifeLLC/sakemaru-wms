<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class RankHistory extends CustomModel
{
    use HasFactory;

    protected $guarded = [];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
