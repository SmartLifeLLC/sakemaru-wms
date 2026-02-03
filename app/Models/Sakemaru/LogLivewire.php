<?php

namespace App\Models\Sakemaru;

class LogLivewire extends CustomModel
{
    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
    ];
}
