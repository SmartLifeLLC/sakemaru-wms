<?php

namespace App\Filament\Resources\ClientPrinterCourseSettingResource\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class ClientPrinterCourseSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('プリンター設定')
                    ->description('配送コースごとに使用するプリンターを設定します')
                    ->schema([
                        Select::make('warehouse_id')
                            ->label('倉庫')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('warehouses')
                                    ->where('is_active', true)
                                    ->selectRaw("id, CONCAT(code, ' - ', name) as label")
                                    ->pluck('label', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                return DB::connection('sakemaru')
                                    ->table('warehouses')
                                    ->where('is_active', true)
                                    ->where(function ($query) use ($search) {
                                        $query->where('name', 'like', "%{$search}%")
                                              ->orWhere('code', 'like', "%{$search}%");
                                    })
                                    ->selectRaw("id, CONCAT(code, ' - ', name) as label")
                                    ->pluck('label', 'id')
                                    ->toArray();
                            })
                            ->live()
                            ->afterStateUpdated(function (callable $set) {
                                $set('delivery_course_id', null);
                                $set('printer_driver_id', null);
                            }),

                        Select::make('delivery_course_id')
                            ->label('配送コース')
                            ->options(function (callable $get) {
                                $warehouseId = $get('warehouse_id');

                                if (! $warehouseId) {
                                    return [];
                                }

                                return DB::connection('sakemaru')
                                    ->table('delivery_courses')
                                    ->where('warehouse_id', $warehouseId)
                                    ->selectRaw("id, CONCAT(code, ' - ', name) as label")
                                    ->pluck('label', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search, callable $get) {
                                $warehouseId = $get('warehouse_id');

                                if (! $warehouseId) {
                                    return [];
                                }

                                return DB::connection('sakemaru')
                                    ->table('delivery_courses')
                                    ->where('warehouse_id', $warehouseId)
                                    ->where(function ($query) use ($search) {
                                        $query->where('name', 'like', "%{$search}%")
                                              ->orWhere('code', 'like', "%{$search}%");
                                    })
                                    ->selectRaw("id, CONCAT(code, ' - ', name) as label")
                                    ->pluck('label', 'id')
                                    ->toArray();
                            })
                            ->disabled(fn (callable $get) => ! $get('warehouse_id'))
                            ->helperText('先に倉庫を選択してください'),

                        Select::make('printer_driver_id')
                            ->label('プリンター')
                            ->options(function (callable $get) {
                                $warehouseId = $get('warehouse_id');

                                if (! $warehouseId) {
                                    return [];
                                }

                                return DB::connection('sakemaru')
                                    ->table('client_printer_drivers')
                                    ->where('warehouse_id', $warehouseId)
                                    ->where('is_active', true)
                                    ->selectRaw("id, CONCAT(name, ' #', printer_index) as label")
                                    ->pluck('label', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search, callable $get) {
                                $warehouseId = $get('warehouse_id');

                                if (! $warehouseId) {
                                    return [];
                                }

                                return DB::connection('sakemaru')
                                    ->table('client_printer_drivers')
                                    ->where('warehouse_id', $warehouseId)
                                    ->where('is_active', true)
                                    ->where(function ($query) use ($search) {
                                        $query->where('name', 'like', "%{$search}%")
                                              ->orWhere('code', 'like', "%{$search}%");
                                    })
                                    ->selectRaw("id, CONCAT(name, ' #', printer_index) as label")
                                    ->pluck('label', 'id')
                                    ->toArray();
                            })
                            ->disabled(fn (callable $get) => ! $get('warehouse_id'))
                            ->helperText('先に倉庫を選択してください'),

                        Toggle::make('is_active')
                            ->label('有効')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
