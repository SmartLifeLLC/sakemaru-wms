<?php

namespace App\Filament\Resources\WmsOrderCandidates;

use App\Enums\EMenu;
use App\Filament\Resources\WmsOrderCandidates\Pages\ListWmsOrderCandidates;
use App\Filament\Resources\WmsOrderCandidates\Tables\WmsOrderCandidatesTable;
use App\Models\WmsOrderCandidate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsOrderCandidateResource extends Resource
{
    protected static ?string $model = WmsOrderCandidate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_ORDER_CANDIDATES->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_ORDER_CANDIDATES->label();
    }

    public static function getModelLabel(): string
    {
        return '発注候補';
    }

    public static function getPluralModelLabel(): string
    {
        return '発注候補';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_ORDER_CANDIDATES->sort();
    }

    public static function table(Table $table): Table
    {
        return WmsOrderCandidatesTable::configure($table);
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
            'index' => ListWmsOrderCandidates::route('/'),
        ];
    }
}
