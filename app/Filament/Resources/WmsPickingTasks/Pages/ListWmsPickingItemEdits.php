<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsPickingTasks\WmsPickingItemEditResource;
use App\Filament\Resources\WmsPickingTasks\Widgets\PickingTaskInfoWidget;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListWmsPickingItemEdits extends ListRecords
{
    use ExposesTableToWidgets;
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsPickingItemEditResource::class;

    protected static ?string $title = 'ピッキング明細編集';

    public ?int $pickingTaskId = null;

    public function mount(): void
    {
        parent::mount();

        // Get picking_task_id from URL parameters
        $this->pickingTaskId = request()->input('tableFilters.picking_task_id.value');
    }

    public function getHeading(): string
    {
        return 'ピッキング明細編集';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PickingTaskInfoWidget::make(['pickingTaskId' => $this->pickingTaskId]),
        ];
    }

    public function getPresetViews(): array
    {
        return [
            'default' => PresetView::make()->modifyQueryUsing(fn(Builder $query) => $query->where('has_soft_shortage', true))->favorite()->label('引き当て欠品あり')->default(),
            'all' => PresetView::make()->modifyQueryUsing(fn(Builder $query) => $query)->favorite()->label('全体'),
        ];
    }
}
