<?php

namespace App\Filament\Resources\WmsOrderConfirmationWaiting;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\EMenu;
use App\Filament\Resources\WmsOrderConfirmationWaiting\Pages\ListWmsOrderConfirmationWaiting;
use App\Filament\Resources\WmsOrderConfirmationWaiting\Tables\WmsOrderConfirmationWaitingTable;
use App\Models\WmsOrderCandidate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsOrderConfirmationWaitingResource extends Resource
{
    protected static ?string $model = WmsOrderCandidate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $slug = 'wms-order-confirmation-waiting';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_ORDER_CONFIRMATION_WAITING->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_ORDER_CONFIRMATION_WAITING->label();
    }

    public static function getModelLabel(): string
    {
        return '発注確定待ち';
    }

    public static function getPluralModelLabel(): string
    {
        return '発注確定待ち';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_ORDER_CONFIRMATION_WAITING->sort();
    }

    public static function getEloquentQuery(): Builder
    {
        // 承認済み（APPROVED）のみ表示
        // 発注確定済み（CONFIRMED）は別画面、送信済み（EXECUTED）は表示しない
        return parent::getEloquentQuery()
            ->where('status', CandidateStatus::APPROVED)
            ->with([
                'warehouse',
                'item',
                'contractor',
            ]);
    }

    public static function table(Table $table): Table
    {
        return WmsOrderConfirmationWaitingTable::configure($table);
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
            'index' => ListWmsOrderConfirmationWaiting::route('/'),
        ];
    }
}
