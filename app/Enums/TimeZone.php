<?php

namespace App\Enums;

namespace App\Enums;

use Carbon\Carbon;

enum TimeZone: string
{
    case TOKYO = 'Asia/Tokyo';

    public function now(): Carbon
    {
        return now($this->value);
    }
}
