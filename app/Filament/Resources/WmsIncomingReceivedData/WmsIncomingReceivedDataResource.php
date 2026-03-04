<?php

namespace App\Filament\Resources\WmsIncomingReceivedData;

use App\Enums\EMenu;
use App\Filament\Resources\WmsIncomingReceivedData\Pages\ListWmsIncomingReceivedData;
use App\Filament\Resources\WmsIncomingReceivedData\Tables\WmsIncomingReceivedDataTable;
use App\Models\WmsIncomingReceivedFile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsIncomingReceivedDataResource extends Resource
{
    protected static ?string $model = WmsIncomingReceivedFile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $slug = 'wms-incoming-received-data';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_INCOMING_RECEIVED_DATA->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_INCOMING_RECEIVED_DATA->label();
    }

    public static function getModelLabel(): string
    {
        return '入荷データ受信';
    }

    public static function getPluralModelLabel(): string
    {
        return '入荷データ受信';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_INCOMING_RECEIVED_DATA->sort();
    }

    public static function table(Table $table): Table
    {
        return WmsIncomingReceivedDataTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsIncomingReceivedData::route('/'),
        ];
    }
}
