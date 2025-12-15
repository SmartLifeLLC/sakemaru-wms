<?php

namespace App\Filament\Resources\WmsOrderCandidates\Schemas;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\QuantityType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WmsOrderCandidateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('batch_code')
                            ->label('バッチコード'),

                        TextEntry::make('warehouse.name')
                            ->label('Hub倉庫'),

                        TextEntry::make('item.item_name')
                            ->label('商品名'),

                        TextEntry::make('contractor.contractor_name')
                            ->label('発注先'),
                    ]),

                Section::make('数量情報')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('self_shortage_qty')
                            ->label('自倉庫不足数')
                            ->suffix(fn ($record) => $record->quantity_type?->name() ?? 'バラ'),

                        TextEntry::make('satellite_demand_qty')
                            ->label('Satellite需要数')
                            ->suffix(fn ($record) => $record->quantity_type?->name() ?? 'バラ'),

                        TextEntry::make('total_required')
                            ->label('合計必要数')
                            ->state(fn ($record) => $record->self_shortage_qty + $record->satellite_demand_qty)
                            ->suffix(fn ($record) => $record->quantity_type?->name() ?? 'バラ'),

                        TextEntry::make('suggested_quantity')
                            ->label('算出数量')
                            ->suffix(fn ($record) => $record->quantity_type?->name() ?? 'バラ'),

                        TextInput::make('order_quantity')
                            ->label('発注数量')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->suffix(fn ($record) => $record->quantity_type?->name() ?? 'バラ'),

                        Select::make('quantity_type')
                            ->label('数量単位')
                            ->options(QuantityType::class)
                            ->required(),
                    ]),

                Section::make('日程')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('original_arrival_date')
                            ->label('元入荷予定日')
                            ->date(),

                        DatePicker::make('expected_arrival_date')
                            ->label('入荷予定日')
                            ->required(),
                    ]),

                Section::make('ステータス')
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->label('承認ステータス')
                            ->options(CandidateStatus::class)
                            ->required(),

                        Select::make('lot_status')
                            ->label('ロットステータス')
                            ->options(LotStatus::class)
                            ->required(),

                        TextEntry::make('transmission_status')
                            ->label('送信ステータス'),

                        TextEntry::make('transmitted_at')
                            ->label('送信日時')
                            ->dateTime(),

                        Textarea::make('exclusion_reason')
                            ->label('除外理由')
                            ->rows(2)
                            ->visible(fn ($record) => $record?->status === CandidateStatus::EXCLUDED),
                    ]),

                Section::make('ロット調整情報')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('lot_before_qty')
                            ->label('調整前数量'),

                        TextEntry::make('lot_after_qty')
                            ->label('調整後数量'),

                        TextEntry::make('lot_fee_type')
                            ->label('手数料タイプ'),

                        TextEntry::make('lot_fee_amount')
                            ->label('手数料金額')
                            ->money('JPY'),
                    ]),

                Section::make('更新履歴')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('is_manually_modified')
                            ->label('手動修正')
                            ->state(fn ($record) => $record->is_manually_modified ? 'あり' : 'なし'),

                        TextEntry::make('modified_by')
                            ->label('修正者ID'),

                        TextEntry::make('modified_at')
                            ->label('修正日時')
                            ->dateTime(),
                    ]),
            ]);
    }
}
