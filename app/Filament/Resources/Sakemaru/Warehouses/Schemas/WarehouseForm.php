<?php

namespace App\Filament\Resources\Sakemaru\Warehouses\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class WarehouseForm
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
                TextInput::make('code')
                    ->label('倉庫コード')
                    ->required()
                    ->numeric()
                    ->maxLength(10),
                TextInput::make('name')
                    ->label('倉庫名')
                    ->required()
                    ->maxLength(255),
                TextInput::make('kana_name')
                    ->label('倉庫名（カナ）')
                    ->maxLength(255),
                TextInput::make('abbreviation')
                    ->label('略称')
                    ->maxLength(100),
                Select::make('out_of_stock_option')
                    ->label('在庫切れオプション')
                    ->options([
                        'IGNORE_STOCK' => '在庫を無視',
                        'UP_TO_STOCK' => '在庫まで',
                    ])
                    ->required()
                    ->default('UP_TO_STOCK'),
                Toggle::make('is_active')
                    ->label('有効')
                    ->default(true),
            ]);
    }
}
