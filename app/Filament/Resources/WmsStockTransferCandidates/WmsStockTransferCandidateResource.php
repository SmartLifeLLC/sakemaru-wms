<?php

namespace App\Filament\Resources\WmsStockTransferCandidates;

use App\Enums\EMenu;
use App\Filament\Resources\WmsStockTransferCandidates\Pages\ListWmsStockTransferCandidates;
use App\Filament\Resources\WmsStockTransferCandidates\Tables\WmsStockTransferCandidatesTable;
use App\Models\WmsStockTransferCandidate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsStockTransferCandidateResource extends Resource
{
    protected static ?string $model = WmsStockTransferCandidate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_STOCK_TRANSFER_CANDIDATES->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_STOCK_TRANSFER_CANDIDATES->label();
    }

    public static function getModelLabel(): string
    {
        return '移動候補';
    }

    public static function getPluralModelLabel(): string
    {
        return '移動候補';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_STOCK_TRANSFER_CANDIDATES->sort();
    }

    public static function table(Table $table): Table
    {
        return WmsStockTransferCandidatesTable::configure($table);
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
            'index' => ListWmsStockTransferCandidates::route('/'),
        ];
    }
}
