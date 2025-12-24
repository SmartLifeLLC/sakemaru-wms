<?php

namespace App\Filament\Resources\WmsItemSupplySettings\Schemas;

use App\Enums\AutoOrder\SupplyType;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WmsItemSupplySettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->schema([
                        Select::make('warehouse_id')
                            ->label('倉庫')
                            ->options(fn () => Warehouse::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->live()
                            ->helperText('発注・移動の発生元倉庫'),

                        Select::make('item_id')
                            ->label('商品')
                            ->options(fn () => Item::query()
                                ->limit(100)
                                ->get()
                                ->mapWithKeys(fn ($item) => [$item->id => "[{$item->item_code}] {$item->item_name}"]))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Item::query()
                                ->where('item_code', 'like', "%{$search}%")
                                ->orWhere('item_name', 'like', "%{$search}%")
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn ($item) => [$item->id => "[{$item->item_code}] {$item->item_name}"]))
                            ->required()
                            ->live()
                            ->helperText('供給対象の商品'),
                    ])
                    ->columns(2),

                Section::make('供給元設定')
                    ->schema([
                        Select::make('supply_type')
                            ->label('供給タイプ')
                            ->options([
                                SupplyType::EXTERNAL->value => '外部発注（問屋から）',
                                SupplyType::INTERNAL->value => '内部移動（他倉庫から）',
                            ])
                            ->default(SupplyType::EXTERNAL->value)
                            ->required()
                            ->live()
                            ->helperText('EXTERNAL: 外部発注、INTERNAL: 倉庫間移動'),

                        Select::make('source_warehouse_id')
                            ->label('供給元倉庫')
                            ->options(fn (Get $get) => Warehouse::where('is_active', true)
                                ->where('id', '!=', $get('warehouse_id'))
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->visible(fn (Get $get) => $get('supply_type') === SupplyType::INTERNAL->value)
                            ->required(fn (Get $get) => $get('supply_type') === SupplyType::INTERNAL->value)
                            ->helperText('在庫を移動してくる元の倉庫'),

                        Select::make('item_contractor_id')
                            ->label('商品発注先')
                            ->options(fn (Get $get) => ItemContractor::query()
                                ->when($get('item_id'), fn ($q, $itemId) => $q->where('item_id', $itemId))
                                ->when($get('warehouse_id'), fn ($q, $warehouseId) => $q->where('warehouse_id', $warehouseId))
                                ->with(['contractor', 'item'])
                                ->get()
                                ->mapWithKeys(fn ($ic) => [
                                    $ic->id => $ic->contractor?->name ?? '不明'
                                ]))
                            ->searchable()
                            ->visible(fn (Get $get) => $get('supply_type') === SupplyType::EXTERNAL->value)
                            ->required(fn (Get $get) => $get('supply_type') === SupplyType::EXTERNAL->value)
                            ->helperText('商品発注先マスタから選択'),
                    ])
                    ->columns(3),

                Section::make('発注パラメータ')
                    ->schema([
                        TextInput::make('lead_time_days')
                            ->label('リードタイム')
                            ->numeric()
                            ->default(1)
                            ->minValue(0)
                            ->suffix('日')
                            ->required()
                            ->helperText('発注から入荷までの日数'),

                        TextInput::make('daily_consumption_qty')
                            ->label('日販予測')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->suffix('個/日')
                            ->required()
                            ->helperText('1日あたりの消費予測数'),

                        TextInput::make('hierarchy_level')
                            ->label('階層レベル')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('0=最下流、数値が大きいほど上流'),

                        Toggle::make('is_enabled')
                            ->label('有効')
                            ->default(true)
                            ->helperText('無効にすると自動発注計算から除外'),
                    ])
                    ->columns(2),
            ]);
    }
}
