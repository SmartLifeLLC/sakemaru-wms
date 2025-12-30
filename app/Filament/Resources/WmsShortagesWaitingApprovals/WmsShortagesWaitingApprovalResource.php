<?php

namespace App\Filament\Resources\WmsShortagesWaitingApprovals;

use App\Enums\EMenu;
use App\Enums\EMenuCategory;
use App\Filament\Resources\WmsShortagesWaitingApprovals\Pages\ListWmsShortagesWaitingApprovals;
use App\Filament\Resources\WmsShortagesWaitingApprovals\Pages\ViewWmsShortagesWaitingApproval;
use App\Filament\Resources\WmsShortagesWaitingApprovals\Tables\WmsShortagesWaitingApprovalsTable;
use App\Models\WmsShortage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsShortagesWaitingApprovalResource extends Resource
{
    protected static ?string $model = WmsShortage::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'warehouse',
                'wave',
                'item',
                'trade.partner',
                'trade.earning.delivery_course',
                'trade.earning.buyer.current_detail.salesman',
                'confirmedBy',
                'confirmedUser',
                'allocations',
            ])
            ->withSum('allocations as allocations_total_qty', 'assign_qty');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?string $navigationLabel = '欠品承認待ち';

    protected static ?string $modelLabel = '欠品承認待ち';

    protected static ?string $pluralModelLabel = '欠品承認待ち';

    protected static ?string $slug = 'wms-shortages-waiting-approval';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_SHORTAGES_WAITING_APPROVALS->category()->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_SHORTAGES_WAITING_APPROVALS->sort();
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
