<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Filament\Resources\WmsShortageAllocations\WmsShortageAllocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsShortageAllocations extends ListRecords
{
    protected static string $resource = WmsShortageAllocationResource::class;

    protected static ?string $title = '横持ち出荷依頼';

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction::make(), // 作成ボタンを削除
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('is_finished', false)
                ->with([
                    'shortage.wave',
                    'shortage.warehouse',
                    'shortage.item',
                    'shortage.trade.partner',
                    'targetWarehouse',
                    'sourceWarehouse',
                    'deliveryCourse',
                ])
            );
    }
}
