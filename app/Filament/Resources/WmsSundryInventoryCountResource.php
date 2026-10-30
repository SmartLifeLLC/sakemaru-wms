<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WmsSundryInventoryCount\Pages\ListWmsSundryInventoryCounts;
use App\Filament\Resources\WmsSundryInventoryCount\Pages\ViewWmsSundryInventoryCount;
use App\Filament\Resources\WmsSundryInventoryCount\Tables\WmsSundryInventoryCountTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsSundryInventoryCount;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 棚卸し（雑貨）。既存の「棚卸し」とは別メニュー・別テーブルで動く金額ベースの棚卸し。
 */
class WmsSundryInventoryCountResource extends AdminResource
{
    protected static ?string $model = WmsSundryInventoryCount::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 30;

    /**
     * 既存の「棚卸し」と同じ権限で利用する（権限マスタの追加登録を不要にする）。
     */
    protected static string $permissionResource = 'wms-inventory-count';

    public static function getNavigationGroup(): ?string
    {
        return '在庫管理';
    }

    public static function getNavigationLabel(): string
    {
        return '棚卸し（雑貨）';
    }

    public static function getModelLabel(): string
    {
        return '棚卸し（雑貨）';
    }

    public static function getPluralModelLabel(): string
    {
        return '棚卸し（雑貨）';
    }

    public static function table(Table $table): Table
    {
        return WmsSundryInventoryCountTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['createdByUser']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsSundryInventoryCounts::route('/'),
            'view' => ViewWmsSundryInventoryCount::route('/{record}'),
        ];
    }
}
