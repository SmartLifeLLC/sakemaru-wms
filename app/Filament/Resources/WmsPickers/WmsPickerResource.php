<?php

namespace App\Filament\Resources\WmsPickers;

use App\Enums\EMenu;
use App\Filament\Resources\WmsPickers\Pages\CreateWmsPicker;
use App\Filament\Resources\WmsPickers\Pages\EditWmsPicker;
use App\Filament\Resources\WmsPickers\Pages\ListWmsPickers;
use App\Filament\Resources\WmsPickers\Schemas\WmsPickerForm;
use App\Filament\Resources\WmsPickers\Tables\WmsPickersTable;
use App\Models\WmsPicker;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsPickerResource extends Resource
{
    protected static ?string $model = WmsPicker::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = 'ピッカー';

    protected static ?string $pluralModelLabel = 'ピッカー';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_PICKERS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_PICKERS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_PICKERS->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return WmsPickerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsPickersTable::configure($table);
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
            'index' => ListWmsPickers::route('/'),
            'create' => CreateWmsPicker::route('/create'),
            'edit' => EditWmsPicker::route('/{record}/edit'),
        ];
    }

    // Navigation badge disabled for performance
    // public static function getNavigationBadge(): ?string
    // {
    //     return static::getModel()::active()->count() ?: null;
    // }

    // public static function getNavigationBadgeColor(): ?string
    // {
    //     return 'success';
    // }
}
