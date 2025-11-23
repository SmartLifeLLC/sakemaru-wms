<?php

namespace App\Filament\Resources\WmsPickingTasks;

use App\Enums\EMenuCategory;
use App\Filament\Resources\WmsPickingTasks\Pages\ListWmsPickingWaitings;
use App\Models\WmsPickingTask;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

class WmsPickingWaitingResource extends Resource
{
    protected static ?string $model = WmsPickingTask::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'ピッキング待ち';

    protected static ?string $modelLabel = 'ピッキング待ち';

    protected static ?string $slug = 'wms-picking-waitings';

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::OUTBOUND->label();
    }

    public static function getEloquentQuery(): Builder
    {
        // Filter for tasks that are waiting (PENDING)
        return parent::getEloquentQuery()->where('status', WmsPickingTask::STATUS_PENDING);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsPickingWaitings::route('/'),
        ];
    }
}
