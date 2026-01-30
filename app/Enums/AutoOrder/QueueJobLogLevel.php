<?php

namespace App\Enums\AutoOrder;

enum QueueJobLogLevel: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case ERROR = 'error';

    public function label(): string
    {
        return match ($this) {
            self::INFO => '情報',
            self::WARNING => '警告',
            self::ERROR => 'エラー',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::INFO => 'gray',
            self::WARNING => 'warning',
            self::ERROR => 'danger',
        };
    }
}
