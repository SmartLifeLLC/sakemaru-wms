<?php

namespace App\Filament\Resources\WmsInventoryCount\RelationManagers;

use App\Filament\Resources\WmsInventoryCount\Tables\WmsInventoryCountItemTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = '棚卸し明細';

    public function table(Table $table): Table
    {
        return WmsInventoryCountItemTable::configure($table);
    }
}
