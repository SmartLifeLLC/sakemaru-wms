<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsPickingItemResults\WmsPickingItemResultResource;
use App\Models\Sakemaru\ClientSetting;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use App\Filament\Resources\WmsPickingTasks\Widgets\PickingTaskInfoWidget;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListWmsPickingItemResults extends ListRecords
{
    use ExposesTableToWidgets;
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsPickingItemResultResource::class;

    public ?int $pickingTaskId = null;

    public function mount(): void
    {
        parent::mount();

        // Get picking_task_id from URL parameters
        $this->pickingTaskId = request()->input('tableFilters.picking_task_id.value');
    }

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }

    public function getPresetViews(): array
    {
        return [
            'default' => PresetView::make()->modifyQueryUsing(fn(Builder $query) => $query->where('has_soft_shortage', true))->favorite()->label('引き当て欠品あり')->default(),
            'all' => PresetView::make()->modifyQueryUsing(fn(Builder $query) => $query)->favorite()->label('全体'),



        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PickingTaskInfoWidget::make(['pickingTaskId' => $this->pickingTaskId]),
        ];
    }
}
