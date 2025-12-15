<?php

namespace App\Enums\AutoOrder;

enum TransmissionType: string
{
    case JX_FINET = 'JX_FINET';
    case MANUAL_CSV = 'MANUAL_CSV';
    case FTP = 'FTP';

    public function label(): string
    {
        return match ($this) {
            self::JX_FINET => 'JX-FINET',
            self::MANUAL_CSV => 'CSV手動',
            self::FTP => 'FTP送信',
        };
    }
}
