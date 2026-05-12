<?php

namespace App\Filament\Resources\WmsShortagesApproved;

use App\Enums\EMenu;
use App\Filament\Resources\WmsShortagesApproved\Pages\ListWmsShortagesApproved;
use App\Filament\Resources\WmsShortagesApproved\Tables\WmsShortagesApprovedTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsShortage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsShortagesApprovedResource extends AdminResource
{
    protected static ?string $model = WmsShortage::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'warehouse:id,code,name,latitude,longitude',
                'location:id,code1,code2,code3',
                'item:id,code,name,capacity_case,volume,volume_unit',
                'trade:id,serial_id,partner_id',
                'trade.partner:id,code,name,latitude,longitude',
                'confirmedBy:id,name',
                'confirmedUser:id,name',
            ])
            ->withSum('allocations as allocations_total_qty', 'assign_qty');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $navigationLabel = '欠品承認済み';

    protected static ?string $modelLabel = '欠品承認済み';

    protected static ?string $pluralModelLabel = '欠品承認済み';

    protected static ?string $slug = 'wms-shortages-approved';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_SHORTAGES_APPROVED->category()->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_SHORTAGES_APPROVED->sort();
    }

    public static function table(Table $table): Table
    {
        return WmsShortagesApprovedTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsShortagesApproved::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
