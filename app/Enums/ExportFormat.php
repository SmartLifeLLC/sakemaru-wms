<?php

namespace App\Enums;

enum ExportFormat: string
{
    case CSV = 'csv';
    case XLSX = 'xlsx';

    public function label(): string
    {
        return match ($this) {
            self::CSV => 'CSV',
            self::XLSX => 'Excel (XLSX)',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::CSV => 'text/csv',
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }
}
