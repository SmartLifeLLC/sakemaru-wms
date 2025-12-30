<?php

namespace App\Enums\AutoOrder;

use Filament\Support\Contracts\HasLabel;

enum TransmissionDocumentType: string implements HasLabel
{
    case PURCHASE = 'PURCHASE';
    case TRANSFER = 'TRANSFER';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::PURCHASE => '発注',
            self::TRANSFER => '移動',
        };
    }
}
