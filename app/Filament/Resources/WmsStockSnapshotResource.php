<?php

namespace App\Filament\Resources;

use App\Enums\EMenu;
use App\Filament\Resources\WmsStockSnapshot\Pages\ListWmsStockSnapshots;
use App\Filament\Resources\WmsStockSnapshot\Tables\WmsStockSnapshotTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsStockSnapshot;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WmsStockSnapshotResource extends AdminResource
{
    protected static ?string $model = WmsStockSnapshot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_STOCK_SNAPSHOTS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_STOCK_SNAPSHOTS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_STOCK_SNAPSHOTS->sort();
    }

    public static function getModelLabel(): string
    {
        return '在庫スナップショット';
    }

    public static function getPluralModelLabel(): string
    {
        return '在庫スナップショット';
    }

    public static function table(Table $table): Table
    {
        return WmsStockSnapshotTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsStockSnapshots::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
