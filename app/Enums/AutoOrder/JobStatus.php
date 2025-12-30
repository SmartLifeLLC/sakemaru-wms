<?php

namespace App\Enums\AutoOrder;

enum JobStatus: string
{
    case PENDING = 'PENDING';
    case RUNNING = 'RUNNING';
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '待機中',
            self::RUNNING => '実行中',
            self::SUCCESS => '成功',
            self::FAILED => '失敗',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::RUNNING => 'info',
            self::SUCCESS => 'success',
            self::FAILED => 'danger',
        };
    }
}
