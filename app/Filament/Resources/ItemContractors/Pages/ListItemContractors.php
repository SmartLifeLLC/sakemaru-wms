<?php

namespace App\Filament\Resources\ItemContractors\Pages;

use App\Filament\Resources\ItemContractors\ItemContractorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListItemContractors extends ListRecords
{
    protected static string $resource = ItemContractorResource::class;

    protected static ?string $title = '商品発注先一覧';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('新規作成'),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['item', 'warehouse', 'contractor', 'supplier.partner'])
            );
    }
}
