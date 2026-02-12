<?php

namespace App\Filament\Resources\WmsContractorWarehouseSettings\Schemas;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WmsContractorWarehouseSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本設定')
                    ->schema([
                        Select::make('contractor_id')
                            ->label('発注先')
                            ->options(fn () => Contractor::query()
                                ->where('is_active', true)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"]))
                            ->searchable()
                            ->required()
                            ->disabledOn('edit'),

                        Select::make('warehouse_id')
                            ->label('倉庫')
                            ->options(fn () => Warehouse::query()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}] {$w->name}"]))
                            ->searchable()
                            ->required()
                            ->disabledOn('edit'),

                        TextInput::make('designated_code')
                            ->label('納入先指定コード')
                            ->maxLength(255)
                            ->helperText('FAX発注書に表示される納入先指定コード'),
                    ])
                    ->columns(2),
            ]);
    }
}
