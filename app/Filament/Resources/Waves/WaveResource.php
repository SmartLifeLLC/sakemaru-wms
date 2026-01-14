<?php

namespace App\Filament\Resources\Waves;

use App\Enums\EMenu;
use App\Filament\Resources\Waves\Pages\ListWaves;
use App\Filament\Resources\Waves\Schemas\WaveForm;
use App\Filament\Resources\Waves\Tables\WavesTable;
use App\Models\Wave;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WaveResource extends Resource
{
    protected static ?string $model = Wave::class;

    protected static bool $shouldRegisterNavigation = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WAVES->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WAVES->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WAVES->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return WaveForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WavesTable::configure($table);
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
            'index' => ListWaves::route('/'),
        ];
    }
}
