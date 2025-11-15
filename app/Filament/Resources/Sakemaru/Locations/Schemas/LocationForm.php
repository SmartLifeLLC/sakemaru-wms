<?php

namespace App\Filament\Resources\Sakemaru\Locations\Schemas;

use App\Enums\TemperatureType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('client_id')
                    ->label('クライアント')
                    ->relationship('client', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('floor_id')
                    ->label('フロア')
                    ->relationship('floor', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('code1')
                    ->label('ロケーションコード1')
                    ->helperText('エリアコード (A: ケース, B: バラ, D: 両方)')
                    ->required()
                    ->maxLength(10),
                TextInput::make('code2')
                    ->label('ロケーションコード2')
                    ->maxLength(10),
                TextInput::make('code3')
                    ->label('ロケーションコード3')
                    ->maxLength(10),
                TextInput::make('name')
                    ->label('ロケーション名')
                    ->required()
                    ->maxLength(255),
                Select::make('available_quantity_flags')
                    ->label('利用可能数量タイプ')
                    ->options([
                        1 => 'ケース',
                        2 => 'バラ',
                        3 => 'ケース+バラ',
                        4 => 'ボール',
                        8 => '無し',
                    ])
                    ->helperText('在庫引当時にこのフラグでフィルタリングされます')
                    ->required()
                    ->default(3),
                Select::make('temperature_type')
                    ->label('温度帯')
                    ->options(TemperatureType::options())
                    ->default(TemperatureType::NORMAL->value)
                    ->helperText('保管温度帯を選択してください')
                    ->required(),
                Toggle::make('is_restricted_area')
                    ->label('制限エリア')
                    ->helperText('制限エリアの場合はONにしてください。特定の権限を持つピッカーのみアクセス可能になります。')
                    ->default(false)
                    ->inline(false),
            ]);
    }
}
