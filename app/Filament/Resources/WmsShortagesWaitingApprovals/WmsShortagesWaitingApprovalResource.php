<?php

namespace App\Filament\Resources\WmsShortagesWaitingApprovals;

use App\Enums\EMenuCategory;
use App\Filament\Resources\WmsShortagesWaitingApprovals\Pages\ListWmsShortagesWaitingApprovals;
use App\Filament\Resources\WmsShortagesWaitingApprovals\Pages\ViewWmsShortagesWaitingApproval;
use App\Filament\Resources\WmsShortagesWaitingApprovals\Tables\WmsShortagesWaitingApprovalsTable;
use App\Models\WmsShortage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsShortagesWaitingApprovalResource extends Resource
{
    protected static ?string $model = WmsShortage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?string $navigationLabel = '欠品承認待ち';

    protected static ?string $modelLabel = '欠品承認待ち';

    protected static ?string $pluralModelLabel = '欠品承認待ち';

    protected static ?string $slug = 'wms-shortages-waiting-approval';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::SHORTAGE->label();
    }

    public static function table(Table $table): Table
    {
        return WmsShortagesWaitingApprovalsTable::configure($table);
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
            'index' => ListWmsShortagesWaitingApprovals::route('/'),
            'view' => ViewWmsShortagesWaitingApproval::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
