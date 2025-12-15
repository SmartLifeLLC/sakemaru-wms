<?php

namespace App\Enums\AutoOrder;

use Filament\Support\Contracts\HasLabel;

enum TransmissionDocumentStatus: string implements HasLabel
{
    case PENDING = 'PENDING';
    case TRANSMITTED = 'TRANSMITTED';
    case CONFIRMED = 'CONFIRMED';
    case ERROR = 'ERROR';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::PENDING => '送信待ち',
            self::TRANSMITTED => '送信済み',
            self::CONFIRMED => '確認済み',
            self::ERROR => 'エラー',
        };
    }
}
