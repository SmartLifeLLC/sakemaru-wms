<?php

namespace App\Filament\Resources\WmsShortages;

use App\Enums\EMenuCategory;
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

class WmsShortageResource extends Resource
{
    protected static ?string $model = WmsShortage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = '欠品対応';

    protected static ?string $modelLabel = '欠品';

    protected static ?string $pluralModelLabel = '欠品一覧';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::SHORTAGE->label();
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
