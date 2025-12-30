<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Filament\Resources\WmsPickingTasks\Tables\WmsPickingTasksTable;
use App\Filament\Resources\WmsPickingTasks\WmsCompletedPickingTaskResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListWmsCompletedPickingTasks extends ListRecords
{
    protected static string $resource = WmsCompletedPickingTaskResource::class;

    public function table(Table $table): Table
    {
        return WmsPickingTasksTable::configure($table, isCompletedView: true);
    }
}
