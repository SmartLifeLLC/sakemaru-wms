<?php

namespace App\Filament\Resources\WmsShortages;

use App\Enums\EMenu;
use App\Filament\Resources\WmsShortages\Pages\CreateWmsShortage;
use App\Filament\Resources\WmsShortages\Pages\EditWmsShortage;
use App\Filament\Resources\WmsShortages\Pages\ListWmsShortages;
use App\Filament\Resources\WmsShortages\Schemas\WmsShortageForm;
use App\Filament\Resources\WmsShortages\Tables\WmsShortagesTable;
use App\Models\WmsShortage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsShortageResource extends Resource
{
    protected static ?string $model = WmsShortage::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'warehouse',
                'wave',
                'item',
                'trade.partner',
                'trade.earning.delivery_course',
                'trade.earning.buyer.current_detail.salesman',
                'confirmedBy',
                'confirmedUser',
                'allocations',
            ])
            ->withSum('allocations as allocations_total_qty', 'assign_qty');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = '欠品対応';

    protected static ?string $modelLabel = '欠品';

    protected static ?string $pluralModelLabel = '欠品一覧';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_SHORTAGES->category()->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_SHORTAGES->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return WmsShortageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsShortagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsShortages::route('/'),
            'create' => CreateWmsShortage::route('/create'),
            'edit' => EditWmsShortage::route('/{record}/edit'),
        ];
    }
}
