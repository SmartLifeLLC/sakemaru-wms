<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsPickingItemResults\WmsPickingItemResultResource;
use App\Filament\Resources\WmsPickingTasks\Widgets\PickingTaskInfoWidget;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsPickingItemResults extends ListRecords
{
    use AdvancedTables;
    use ExposesTableToWidgets;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsPickingItemResultResource::class;

    public ?int $pickingTaskId = null;

    // View labels as constants for reuse
    public const VIEW_LABEL_DEFAULT = '引き当て欠品あり';

    public const VIEW_LABEL_ALL = '全体';

    public function mount(): void
    {
        parent::mount();

        // Get picking_task_id from URL parameters
        $this->pickingTaskId = request()->input('tableFilters.picking_task_id.value');
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->emptyStateHeading($this->getEmptyStateHeading())
            ->emptyStateDescription('');
    }

    protected function getEmptyStateHeading(): string
    {
        $activeView = $this->activeView ?? 'default';

        return match ($activeView) {
            'default' => '「'.self::VIEW_LABEL_DEFAULT.'」のデータはありません',
            'all' => '「'.self::VIEW_LABEL_ALL.'」のデータはありません',
            default => 'データが見つかりません',
        };
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
            'default' => PresetView::make()->modifyQueryUsing(fn (Builder $query) => $query->where('has_soft_shortage', true))->favorite()->label(self::VIEW_LABEL_DEFAULT)->default(),
            'all' => PresetView::make()->modifyQueryUsing(fn (Builder $query) => $query)->favorite()->label(self::VIEW_LABEL_ALL),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PickingTaskInfoWidget::make(['pickingTaskId' => $this->pickingTaskId]),
        ];
    }
}
