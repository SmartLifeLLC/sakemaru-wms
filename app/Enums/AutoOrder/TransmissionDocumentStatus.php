<?php

namespace App\Enums\AutoOrder;

use Filament\Support\Contracts\HasLabel;

enum TransmissionDocumentStatus: string implements HasLabel
{
    case DRAFT = 'DRAFT';           // テスト生成（確定前）
    case PENDING = 'PENDING';       // 送信待ち（確定済み）
    case TRANSMITTED = 'TRANSMITTED';
    case CONFIRMED = 'CONFIRMED';
    case ERROR = 'ERROR';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::DRAFT => 'テスト生成',
            self::PENDING => '送信待ち',
            self::TRANSMITTED => '送信済み',
            self::CONFIRMED => '確認済み',
            self::ERROR => 'エラー',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::PENDING => 'warning',
            self::TRANSMITTED => 'success',
            self::CONFIRMED => 'info',
            self::ERROR => 'danger',
        };
    }
}
