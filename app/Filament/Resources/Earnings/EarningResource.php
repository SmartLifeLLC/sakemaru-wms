<?php

namespace App\Filament\Resources\Earnings;

use App\Enums\EMenu;
use App\Filament\Resources\Earnings\Pages\CreateEarning;
use App\Filament\Resources\Earnings\Pages\EditEarning;
use App\Filament\Resources\Earnings\Pages\ListEarnings;
use App\Filament\Resources\Earnings\Schemas\EarningForm;
use App\Filament\Resources\Earnings\Tables\EarningsTable;
use App\Models\Sakemaru\Earning;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EarningResource extends Resource
{
    protected static ?string $model = Earning::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::EARNINGS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::EARNINGS->label();
    }

    public static function getModelLabel(): string
    {
        return '売上データ';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::EARNINGS->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return EarningForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EarningsTable::configure($table);
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
            'index' => ListEarnings::route('/'),
            'create' => CreateEarning::route('/create'),
            'edit' => EditEarning::route('/{record}/edit'),
        ];
    }
}
