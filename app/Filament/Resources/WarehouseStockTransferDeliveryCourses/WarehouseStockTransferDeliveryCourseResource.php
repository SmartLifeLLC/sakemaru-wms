<?php

namespace App\Filament\Resources\WarehouseStockTransferDeliveryCourses;

use App\Enums\EMenuCategory;
use App\Models\Sakemaru\WarehouseStockTransferDeliveryCourse;
use Filament\Resources\Resource;

class WarehouseStockTransferDeliveryCourseResource extends Resource
{
    protected static ?string $model = WarehouseStockTransferDeliveryCourse::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = '移動配送コース設定';

    protected static ?string $modelLabel = '移動配送コース設定';

    protected static ?string $pluralModelLabel = '移動配送コース設定';

    protected static \UnitEnum|string|null $navigationGroup = EMenuCategory::AUTO_ORDER;

    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): ?string
    {
        return self::$navigationGroup?->label();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarehouseStockTransferDeliveryCourses::route('/'),
        ];
    }
}
