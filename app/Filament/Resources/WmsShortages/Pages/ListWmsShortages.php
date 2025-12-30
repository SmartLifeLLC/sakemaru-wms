<?php

namespace App\Filament\Resources\WmsShortages\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsShortages\WmsShortageResource;
use App\Models\Sakemaru\ClientSetting;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsShortages extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsShortageResource::class;

    protected static ?string $title = '';

    protected string $view = 'filament.resources.wms-shortages.pages.list-wms-shortages';

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction::make(), // 作成ボタンを削除
        ];
    }

    public function getPresetViews(): array
    {

        return [
            'default' => PresetView::make()->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'BEFORE'))->favorite()->label('対応待ち')->default(),
            'finished' => PresetView::make()->modifyQueryUsing(fn (Builder $query) => $query->whereNot('status', 'BEFORE')->where('shipment_date',ClientSetting::systemDate()))->favorite()->label('対応完了(当日分)'),
            'finished_all' => PresetView::make()->modifyQueryUsing(fn (Builder $query) => $query->whereNot('status', 'BEFORE'))->favorite()->label('対応完了(全件)')
        ];

    }
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with([
                    'wave',
                    'warehouse',
                    'item',
                    'trade.partner',
                    'trade.earning.delivery_course',
                    'trade.earning.buyer.current_detail.salesman',
                    'allocations.targetWarehouse',
                    'allocations.sourceWarehouse',
                    'updater',
                    'confirmedBy',
                    'confirmedUser',
                ])
                ->withSum('allocations as allocations_total_qty', 'assign_qty')
                ->withSum([
                    'allocations as allocations_case_qty' => function ($query) {
                        $query->where('assign_qty_type', 'CASE');
                    }
                ], 'assign_qty')
                ->withSum([
                    'allocations as allocations_piece_qty' => function ($query) {
                        $query->where('assign_qty_type', 'PIECE');
                    }
                ], 'assign_qty')
            )
            ->recordUrl(null);
    }


}
