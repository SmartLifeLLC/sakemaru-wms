<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Filament\Resources\WmsShortageAllocations\Tables\WmsShortageAllocationsFinishedTable;
use App\Filament\Resources\WmsShortageAllocations\WmsShortageAllocationResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListHistoryWmsShortageAllocations extends ListRecords
{
    protected static string $resource = WmsShortageAllocationResource::class;

    protected static ?string $title = '横持ち出荷履歴';

    protected function getHeaderActions(): array
    {
        return [
            // No actions needed
        ];
    }

    public function table(Table $table): Table
    {
        // Use the finished table configuration
        $table = WmsShortageAllocationsFinishedTable::configure($table);

        return $table->modifyQueryUsing(fn (Builder $query) => $query
            ->where('is_finished', true)
            ->with([
                'shortage.wave',
                'shortage.warehouse',
                'shortage.item',
                'shortage.trade.partner',
                'targetWarehouse',
                'sourceWarehouse',
                'finishedUser',
                'deliveryCourse',
            ])
        );
    }
}
