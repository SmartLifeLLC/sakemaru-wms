<?php

namespace App\Filament\Resources\WmsPickerAttendance\Pages;

use App\Filament\Resources\WmsPickerAttendance\WmsPickerAttendanceResource;
use Filament\Resources\Pages\ListRecords;

class ListWmsPickerAttendance extends ListRecords
{
    protected static string $resource = WmsPickerAttendanceResource::class;

    protected static ?string $title = 'ピッカー勤怠管理';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
