<?php

namespace App\Enums\AutoOrder;

use Filament\Support\Contracts\HasLabel;

enum OrderDataFileStatus: string implements HasLabel
{
    case GENERATED = 'GENERATED';
    case DOWNLOADED = 'DOWNLOADED';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::GENERATED => '未ダウンロード',
            self::DOWNLOADED => 'ダウンロード済',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::GENERATED => 'warning',
            self::DOWNLOADED => 'success',
        };
    }
}
