<?php

namespace App\Filament\Resources\WmsJxUnknownIncomingSlips\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsJxUnknownIncomingSlips\WmsJxUnknownIncomingSlipResource;
use Archilex\AdvancedTables\AdvancedTables;
use Filament\Resources\Pages\ListRecords;

class ListWmsJxUnknownIncomingSlips extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsJxUnknownIncomingSlipResource::class;
}
