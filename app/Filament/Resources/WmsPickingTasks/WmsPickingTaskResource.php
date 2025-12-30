<?php

namespace App\Filament\Resources\WmsPickingTasks;

use App\Enums\EMenu;
use App\Filament\Resources\WmsPickingTasks\Pages\ExecuteWmsPickingTask;
use App\Filament\Resources\WmsPickingTasks\Pages\ListWmsPickingTasks;
use App\Models\WmsPickingTask;
use Filament\Support\Enums\IconSize;
use Filament\Resources\Resource;
use Illuminate\Support\HtmlString;
use UnitEnum;
use BackedEnum;

class WmsPickingTaskResource extends Resource
{
    protected static ?string $model = WmsPickingTask::class;

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->where('status', '!=', 'COMPLETED')
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

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = 'ピッキングタスク';

    protected static ?string $pluralModelLabel = 'ピッキングタスク';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::PICKING_TASKS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::PICKING_TASKS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::PICKING_TASKS->sort();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsPickingTasks::route('/'),
            'execute' => ExecuteWmsPickingTask::route('/{record}/execute'),
        ];
    }

    // Navigation badge disabled for performance
    // public static function getNavigationBadge(): ?string
    // {
    //     return static::getModel()::unassigned()->inProgress()->count() ?: null;
    // }

    // public static function getNavigationBadgeColor(): ?string
    // {
    //     $count = static::getModel()::unassigned()->inProgress()->count();

    //     if ($count > 10) {
    //         return 'danger';
    //     } elseif ($count > 5) {
    //         return 'warning';
    //     } elseif ($count > 0) {
    //         return 'success';
    //     }

    //     return null;
    // }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return '未割当タスク数';
    }
}
