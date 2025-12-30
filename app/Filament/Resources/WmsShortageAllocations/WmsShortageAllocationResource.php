<?php

namespace App\Filament\Resources\WmsShortageAllocations;

use App\Enums\EMenu;
use App\Filament\Resources\WmsShortageAllocations\Pages\CreateWmsShortageAllocation;
use App\Filament\Resources\WmsShortageAllocations\Pages\EditWmsShortageAllocation;
use App\Filament\Resources\WmsShortageAllocations\Pages\ListFinishedWmsShortageAllocations;
use App\Filament\Resources\WmsShortageAllocations\Pages\ListHistoryWmsShortageAllocations;
use App\Filament\Resources\WmsShortageAllocations\Pages\ListWmsShortageAllocations;
use App\Filament\Resources\WmsShortageAllocations\Schemas\WmsShortageAllocationForm;
use App\Filament\Resources\WmsShortageAllocations\Tables\WmsShortageAllocationsTable;
use App\Models\WmsShortageAllocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsShortageAllocationResource extends Resource
{
    protected static ?string $model = WmsShortageAllocation::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'shortage.warehouse',
                'shortage.item',
                'shortage.trade.partner',
                'targetWarehouse',
                'deliveryCourse',
            ]);
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = '横持ち出荷依頼';

    protected static ?string $modelLabel = '横持ち出荷';

    protected static ?string $pluralModelLabel = '横持ち出荷依頼';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_SHORTAGE_ALLOCATIONS->category()->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_SHORTAGE_ALLOCATIONS->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return WmsShortageAllocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsShortageAllocationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsShortageAllocations::route('/'),
            'finished' => ListFinishedWmsShortageAllocations::route('/finished'),
            'history' => ListHistoryWmsShortageAllocations::route('/history'),
            'create' => CreateWmsShortageAllocation::route('/create'),
            'edit' => EditWmsShortageAllocation::route('/{record}/edit'),
        ];
    }

    public static function getNavigationItems(): array
    {
        return [
            \Filament\Navigation\NavigationItem::make(static::getNavigationLabel())
                ->group(static::getNavigationGroup())
                ->icon(static::getNavigationIcon())
                ->sort(static::getNavigationSort())
                ->url(static::getUrl('index')),
            \Filament\Navigation\NavigationItem::make('横持ち出荷完了一覧')
                ->group(static::getNavigationGroup())
                ->icon('heroicon-o-check-circle')
                ->sort(static::getNavigationSort() + 1)
                ->url(static::getUrl('finished')),
            \Filament\Navigation\NavigationItem::make('横持ち出荷履歴')
                ->group(static::getNavigationGroup())
                ->icon('heroicon-o-clock')
                ->sort(static::getNavigationSort() + 2)
                ->url(static::getUrl('history')),
        ];
    }
}
