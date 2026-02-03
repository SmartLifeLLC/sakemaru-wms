<?php

namespace App\Filament\Resources\WmsItemStockSnapshots;

use App\Enums\EMenu;
use App\Filament\Resources\WmsItemStockSnapshots\Pages\ListWmsItemStockSnapshots;
use App\Filament\Resources\WmsItemStockSnapshots\Tables\WmsItemStockSnapshotsTable;
use App\Models\WmsItemStockSnapshot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsItemStockSnapshotResource extends Resource
{
    protected static ?string $model = WmsItemStockSnapshot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCamera;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_ITEM_STOCK_SNAPSHOTS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_ITEM_STOCK_SNAPSHOTS->label();
    }

    public static function getModelLabel(): string
    {
        return EMenu::WMS_ITEM_STOCK_SNAPSHOTS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_ITEM_STOCK_SNAPSHOTS->sort();
    }

    public static function table(Table $table): Table
    {
        return WmsItemStockSnapshotsTable::configure($table);
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
            'index' => ListWmsItemStockSnapshots::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
