<?php

namespace App\Filament\Resources\WmsContractorWarehouseMappings\Schemas;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsContractorWarehouseMapping;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WmsContractorWarehouseMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('発注先-倉庫マッピング')
                    ->description('発注先が内部倉庫を表す場合、その倉庫をマッピングします。マッピングされた発注先は「内部移動（INTERNAL）」として扱われます。')
                    ->schema([
                        Select::make('contractor_id')
                            ->label('発注先')
                            ->helperText('内部倉庫として登録する発注先を選択')
                            ->options(function () {
                                // 既にマッピングされている発注先を除外
                                $mappedContractorIds = WmsContractorWarehouseMapping::pluck('contractor_id')->toArray();

                                return Contractor::where('is_active', true)
                                    ->whereNotIn('id', $mappedContractorIds)
                                    ->get()
                                    ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"]);
                            })
                            ->searchable()
                            ->required()
                            ->unique(ignoreRecord: true),

                        Select::make('warehouse_id')
                            ->label('対応する倉庫')
                            ->helperText('この発注先が表す倉庫を選択')
                            ->options(fn () => Warehouse::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->required(),

                        Textarea::make('memo')
                            ->label('メモ')
                            ->rows(2)
                            ->maxLength(255),
                    ])
                    ->columns(2),
            ]);
    }
}
