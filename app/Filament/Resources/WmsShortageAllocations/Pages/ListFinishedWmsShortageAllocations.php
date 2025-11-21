<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Filament\Resources\WmsShortageAllocations\Tables\WmsShortageAllocationsFinishedTable;
use App\Filament\Resources\WmsShortageAllocations\WmsShortageAllocationResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListFinishedWmsShortageAllocations extends ListRecords
{
    protected static string $resource = WmsShortageAllocationResource::class;

    protected static ?string $title = '横持ち出荷完了一覧';

    protected function getHeaderActions(): array
    {
        return [
            // No actions needed
        ];
    }

    public function table(Table $table): Table
    {
        // Use the finished table configuration instead of parent
        $table = WmsShortageAllocationsFinishedTable::configure($table);

        // Get system_date from client_settings
        $systemDate = \App\Models\Sakemaru\ClientSetting::first()?->system_date;

        return $table->modifyQueryUsing(fn (Builder $query) => $query
            ->where('is_finished', true)
            ->when($systemDate, fn ($q) => $q->whereDate('shipment_date', $systemDate))
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
