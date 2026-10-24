<?php

namespace App\Models;

class WmsAutoOrderRunLine extends WmsModel
{
    protected $guarded = [];

    protected $casts = [
        'proposal_snapshot' => 'array',
        'confirmation_snapshot' => 'array',
        'artifact_output_snapshot' => 'array',
        'generated_at' => 'datetime',
    ];
}
