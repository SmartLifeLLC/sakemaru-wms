<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Filament\Resources\WmsPickingTasks\Tables\WmsPickingTasksTable;
use App\Filament\Resources\WmsPickingTasks\WmsPickingWaitingResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListWmsPickingWaitings extends ListRecords
{
    protected static string $resource = WmsPickingWaitingResource::class;

    public function table(Table $table): Table
    {
        return WmsPickingTasksTable::configure($table, isWaitingView: true);
    }
}
