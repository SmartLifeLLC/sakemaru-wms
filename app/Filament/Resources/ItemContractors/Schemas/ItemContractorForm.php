<?php

namespace App\Filament\Resources\ItemContractors\Schemas;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Supplier;
use App\Models\Sakemaru\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ItemContractorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.pages.auto-replenishment-master-edit-warning')
                    ->viewData(fn ($record) => app(\App\Services\AutoReplenishment\MasterRouteWarnings::class)
                        ->masterEditNotice((int) auth()->user()?->client_id, 'item', $record?->getAttributes() ?? []))
                    ->columnSpanFull(),
                Section::make('基本情報')
                    ->schema([
                        Select::make('item_id')
                            ->label('商品')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Item::query()
                                ->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%")
                                ->orderBy('code')
                                ->limit(50)
                                ->get(['id', 'code', 'name'])
                                ->mapWithKeys(fn ($item) => [$item->id => "[{$item->code}] {$item->name}"]))
                            ->getOptionLabelUsing(fn ($value) => ($item = Item::find($value)) ? "[{$item->code}] {$item->name}" : null)
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

                        Textarea::make('note')
                            ->label('備考')
                            ->nullable()
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('商品発注先に関するメモや備考を自由に入力できます'),
                    ])
                    ->columns(2),

                Section::make('在庫設定')
                    ->schema([
                        TextInput::make('safety_stock')
                            ->label('発注点')
                            ->numeric()
                            ->rules(['integer'])
                            ->default(0)
                            ->dehydrateStateUsing(fn ($state) => (int) ($state ?? 0))
                            ->minValue(0)
                            ->maxValue(4294967295)
                            ->suffix('バラ')
                            ->visible(fn ($record): bool => ! $record?->is_auto_order_active)
                            ->helperText('手動発注の参考値です。'),

                        TextEntry::make('automatic_reorder_point')
                            ->label('発注点')
                            ->state('自動発注中')
                            ->visible(fn ($record): bool => (bool) $record?->is_auto_order_active),

                        TextInput::make('min_stock')
                            ->label('最低在庫')
                            ->numeric()
                            ->default(0)
                            ->dehydrateStateUsing(fn ($state) => $state ?? 0)
                            ->minValue(0)
                            ->suffix('個')
                            ->helperText('旧システムの最低在庫数'),

                        TextEntry::make('automatic_order_status')
                            ->label('自動発注')
                            ->state(fn ($record): string => $record?->is_auto_order_active ? '自動発注中' : '自動発注OFF')
                            ->helperText('自動発注の設定は「商品別発注設定」で変更します。'),
                    ])
                    ->columns(2),
            ]);
    }
}
