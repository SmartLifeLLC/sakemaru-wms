<?php

namespace App\Filament\Resources\WmsPickingTasks;

use App\Enums\EMenu;
use App\Filament\Resources\WmsPickingTasks\Pages\ListWmsPickingWaitings;
use App\Models\WmsPickingTask;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

class WmsPickingWaitingResource extends Resource
{
    protected static ?string $model = WmsPickingTask::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'ピッキング調整';

    protected static ?string $modelLabel = 'ピッキング調整';

    protected static ?string $slug = 'wms-picking-waitings';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_PICKING_WAITINGS->category()->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_PICKING_WAITINGS->sort();
    }

    public static function getEloquentQuery(): Builder
    {
        // Filter for tasks that are waiting (PENDING)
        return parent::getEloquentQuery()
            ->where('status', WmsPickingTask::STATUS_PENDING)
            ->with([
                'warehouse',
                'floor',
                'picker',
                'deliveryCourse',
                'pickingItemResults.trade',
                'pickingItemResults.earning.buyer.partner',
                'pickingItemResults.stockTransfer.to_warehouse',
            ])
            ->withCount([
                'pickingItemResults as soft_shortage_count' => function ($query) {
                    $query->where('has_soft_shortage', true);
                },
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsPickingWaitings::route('/'),
        ];
    }
}
