<?php

namespace App\Filament\Resources\WmsMonthlySafetyStocks\Schemas;

use App\Models\WmsMonthlySafetyStock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class WmsMonthlySafetyStockForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('月別発注点設定')
                    ->description('商品・倉庫・発注先ごとの月別発注点（安全在庫）を設定します')
                    ->schema([
                        Select::make('warehouse_id')
                            ->label('倉庫')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('warehouses')
                                    ->where('is_active', true)
                                    ->orderBy('code')
                                    ->selectRaw("id, CONCAT('[', code, '] ', name) as label")
                                    ->pluck('label', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                $search = mb_convert_kana($search, 'as');

                                return DB::connection('sakemaru')
                                    ->table('warehouses')
                                    ->where('is_active', true)
                                    ->where(function ($query) use ($search) {
                                        $query->where('name', 'like', "%{$search}%")
                                            ->orWhere('code', 'like', "%{$search}%");
                                    })
                                    ->orderBy('code')
                                    ->selectRaw("id, CONCAT('[', code, '] ', name) as label")
                                    ->pluck('label', 'id')
                                    ->toArray();
                            })
                            ->live()
                            ->afterStateUpdated(function (callable $set) {
                                $set('item_id', null);
                                $set('contractor_id', null);
                            }),

                        Select::make('item_id')
                            ->label('商品')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('items')
                                    ->where('is_active', true)
                                    ->orderBy('code')
                                    ->limit(100)
                                    ->selectRaw("id, CONCAT('[', code, '] ', name) as label")
                                    ->pluck('label', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                $search = mb_convert_kana($search, 'as');

                                return DB::connection('sakemaru')
                                    ->table('items')
                                    ->where('is_active', true)
                                    ->where(function ($query) use ($search) {
                                        $query->where('name', 'like', "%{$search}%")
                                            ->orWhere('code', 'like', "%{$search}%");
                                    })
                                    ->orderBy('code')
                                    ->limit(50)
                                    ->selectRaw("id, CONCAT('[', code, '] ', name) as label")
                                    ->pluck('label', 'id')
                                    ->toArray();
                            })
                            ->live()
                            ->afterStateUpdated(function (callable $set) {
                                $set('contractor_id', null);
                            }),

                        Select::make('contractor_id')
                            ->label('発注先')
                            ->options(function (callable $get) {
                                $warehouseId = $get('warehouse_id');
                                $itemId = $get('item_id');

                                if (! $warehouseId || ! $itemId) {
                                    return [];
                                }

                                // item_contractorsから該当の発注先を取得
                                return DB::connection('sakemaru')
                                    ->table('item_contractors')
                                    ->join('contractors', 'item_contractors.contractor_id', '=', 'contractors.id')
                                    ->where('item_contractors.warehouse_id', $warehouseId)
                                    ->where('item_contractors.item_id', $itemId)
                                    ->orderBy('contractors.code')
                                    ->selectRaw("contractors.id, CONCAT('[', contractors.code, '] ', contractors.name) as label")
                                    ->pluck('label', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search, callable $get) {
                                $warehouseId = $get('warehouse_id');
                                $itemId = $get('item_id');

                                if (! $warehouseId || ! $itemId) {
                                    return [];
                                }

                                $search = mb_convert_kana($search, 'as');

                                return DB::connection('sakemaru')
                                    ->table('item_contractors')
                                    ->join('contractors', 'item_contractors.contractor_id', '=', 'contractors.id')
                                    ->where('item_contractors.warehouse_id', $warehouseId)
                                    ->where('item_contractors.item_id', $itemId)
                                    ->where(function ($query) use ($search) {
                                        $query->where('contractors.name', 'like', "%{$search}%")
                                            ->orWhere('contractors.code', 'like', "%{$search}%");
                                    })
                                    ->orderBy('contractors.code')
                                    ->selectRaw("contractors.id, CONCAT('[', contractors.code, '] ', contractors.name) as label")
                                    ->pluck('label', 'id')
                                    ->toArray();
                            })
                            ->disabled(fn (callable $get) => ! $get('warehouse_id') || ! $get('item_id'))
                            ->helperText('倉庫と商品を先に選択してください'),

                        Select::make('month')
                            ->label('月')
                            ->options(WmsMonthlySafetyStock::getMonthOptions())
                            ->required()
                            ->default(now()->month),

                        TextInput::make('safety_stock')
                            ->label('発注点（安全在庫）')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->minValue(0)
                            ->suffix('個')
                            ->helperText('この数量を下回ると発注候補として算出されます'),
                    ])
                    ->columns(2),
            ]);
    }
}
