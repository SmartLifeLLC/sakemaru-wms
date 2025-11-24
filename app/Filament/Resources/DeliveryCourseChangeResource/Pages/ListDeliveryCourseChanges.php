<?php

namespace App\Filament\Resources\DeliveryCourseChangeResource\Pages;

use App\Filament\Resources\DeliveryCourseChangeResource;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryCourseChanges extends ListRecords
{
    protected static string $resource = DeliveryCourseChangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 必要に応じてヘッダーアクションを追加
        ];
    }
}
