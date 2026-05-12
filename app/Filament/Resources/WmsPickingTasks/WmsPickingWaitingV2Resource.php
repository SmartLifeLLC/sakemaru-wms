<?php

namespace App\Filament\Resources\WmsPickingTasks;

use App\Enums\EMenu;
use App\Filament\Resources\WmsPickingTasks\Pages\ExecuteWmsPickingTaskV2;
use App\Filament\Resources\WmsPickingTasks\Pages\ListWmsPickingWaitingsV2;
use App\Filament\Support\AdminResource;
use App\Models\WmsPickingTask;
use Illuminate\Database\Eloquent\Builder;

class WmsPickingWaitingV2Resource extends AdminResource
{
    protected static ?string $model = WmsPickingTask::class;

    protected static string $permissionResource = 'wms-picking-waiting';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'ピッキング調整 v2';

    protected static ?string $modelLabel = 'ピッキング調整 v2';

    protected static ?string $slug = 'wms-picking-waitings-v2';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_PICKING_WAITINGS->category()->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_PICKING_WAITINGS->sort() + 1;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('wms_picking_tasks.status', [
                WmsPickingTask::STATUS_PENDING,
                WmsPickingTask::STATUS_PICKING_READY,
                WmsPickingTask::STATUS_PICKING,
                WmsPickingTask::STATUS_SHORTAGE,
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsPickingWaitingsV2::route('/'),
            'execute' => ExecuteWmsPickingTaskV2::route('/{record}/execute'),
        ];
    }
}
