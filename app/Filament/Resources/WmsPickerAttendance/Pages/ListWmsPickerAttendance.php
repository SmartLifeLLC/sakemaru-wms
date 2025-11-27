<?php

namespace App\Filament\Resources\WmsPickerAttendance\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsPickerAttendance\Tables\WmsPickerAttendanceTable;
use App\Filament\Resources\WmsPickerAttendance\WmsPickerAttendanceResource;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsPickerAttendance extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsPickerAttendanceResource::class;

    protected static ?string $title = 'ピッカー勤怠管理';

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getPresetViews(): array
    {
        // Use loadMissing to avoid repeated queries
        $user = \Auth::user();
        $user->loadMissing('warehouse');

        return [
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('default_warehouse_id', $user->warehouse->id))
                ->label($user->warehouse->name)
                ->favorite()
                ->default(),

            'all' => PresetView::make()
                ->label('全倉庫')
                ->favorite(),
        ];
    }

    public function table(Table $table): Table
    {
        return WmsPickerAttendanceTable::configure($table);
    }
}
