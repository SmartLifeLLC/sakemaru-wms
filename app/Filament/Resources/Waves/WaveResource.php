<?php

namespace App\Filament\Resources\Waves;

use App\Enums\EMenu;
use App\Filament\Resources\Waves\Pages\ListWaves;
use App\Filament\Resources\Waves\Pages\ViewWaveGroup;
use App\Filament\Resources\Waves\Schemas\WaveForm;
use App\Filament\Resources\Waves\Tables\WaveGroupsTable;
use App\Models\WaveGroup;
use BackedEnum;
use App\Filament\Support\AdminResource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WaveResource extends AdminResource
{
    protected static ?string $model = WaveGroup::class;

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

    public static function getModelLabel(): string
    {
        return '波動生成グループ';
    }

    public static function getPluralModelLabel(): string
    {
        return '波動生成グループ';
    }

    public static function form(Schema $schema): Schema
    {
        return WaveForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WaveGroupsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['warehouse', 'creator'])
            ->withCount('waves');
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
            'view' => ViewWaveGroup::route('/{record}'),
        ];
    }
}
