<?php

namespace App\Filament\Resources\WmsAutoOrderExecutionLogs\Pages;

use App\Filament\Resources\WmsAutoOrderExecutionLogs\WmsAutoOrderExecutionLogResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsAutoOrderExecutionLogs extends ListRecords
{
    protected static string $resource = WmsAutoOrderExecutionLogResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['contractor'])
                ->when(
                    ! request()->has('tableFilters'),
                    fn ($q) => $q->where('executed_date', today())
                )
                ->orderBy('id', 'desc')
            );
    }
}
