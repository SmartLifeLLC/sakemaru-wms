<?php

namespace App\Filament\Resources\Sakemaru\Floors\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FloorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('code')
                    ->label('フロアコード')
                    ->helperText('倉庫コード + 001形式 (例: 991001)')
                    ->required()
                    ->numeric()
                    ->maxLength(10),
                TextInput::make('name')
                    ->label('フロア名')
                    ->required()
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label('有効')
                    ->default(true),
            ]);
    }
}
