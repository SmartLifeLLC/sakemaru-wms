<?php

namespace App\Filament\Resources\ItemContractors\Schemas;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Supplier;
use App\Models\Sakemaru\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ItemContractorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->schema([
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
                            ->helperText('発注対象の商品を選択'),

                        Select::make('warehouse_id')
                            ->label('倉庫')
                            ->options(fn () => Warehouse::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->helperText('この商品を発注する倉庫'),

                        Select::make('contractor_id')
                            ->label('発注先')
                            ->options(fn () => Contractor::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->helperText('発注先の業者'),

                        Select::make('supplier_id')
                            ->label('仕入先')
                            ->options(fn () => Supplier::with('partner')
                                ->get()
                                ->mapWithKeys(fn ($s) => [$s->id => "[{$s->partner?->code}] {$s->partner?->name}"])
                            )
                            ->searchable()
                            ->nullable()
                            ->helperText('仕入先（任意）'),
                    ])
                    ->columns(2),

                Section::make('在庫設定')
                    ->schema([
                        TextInput::make('safety_stock')
                            ->label('安全在庫')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->suffix('個')
                            ->helperText('この数を下回ると発注を検討'),

                        TextInput::make('max_stock')
                            ->label('最大在庫')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->suffix('個')
                            ->helperText('在庫の上限目安'),

                        Toggle::make('is_auto_order')
                            ->label('自動発注対象')
                            ->default(false)
                            ->helperText('自動発注の対象にする場合はON'),
                    ])
                    ->columns(3),
            ]);
    }
}
