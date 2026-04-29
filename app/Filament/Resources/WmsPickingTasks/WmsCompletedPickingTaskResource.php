<?php

namespace App\Filament\Resources\WmsPickingTasks;

use App\Enums\EMenu;
use App\Filament\Resources\WmsPickingTasks\Pages\ListWmsCompletedPickingTasks;
use App\Models\WmsPickingTask;
use App\Filament\Support\AdminResource;
use Illuminate\Database\Eloquent\Builder;

class WmsCompletedPickingTaskResource extends AdminResource
{
    protected static ?string $model = WmsPickingTask::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = 'ピッキング完了履歴';

    protected static ?string $pluralModelLabel = 'ピッキング完了履歴';

    protected static ?string $slug = 'wms-completed-picking-tasks';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::PICKING_TASKS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return 'ピッキング完了履歴';
    }

    public static function getNavigationSort(): ?int
    {
        return 6;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', 'COMPLETED')
            ->with([
                'warehouse',
                'floor',
                'picker',
                'deliveryCourse',
                'pickingItemResults.trade',
                'pickingItemResults.earning.buyer.partner',
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
            'index' => ListWmsCompletedPickingTasks::route('/'),
        ];
    }
}
