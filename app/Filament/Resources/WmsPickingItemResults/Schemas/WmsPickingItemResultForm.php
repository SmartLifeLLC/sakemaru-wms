<?php

namespace App\Filament\Resources\WmsPickingItemResults\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class WmsPickingItemResultForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('picking_task_id')
                    ->relationship('pickingTask', 'id')
                    ->required(),
                Select::make('earning_id')
                    ->relationship('earning', 'id'),
                Select::make('trade_id')
                    ->relationship('trade', 'id')
                    ->required(),
                Select::make('trade_item_id')
                    ->relationship('tradeItem', 'id')
                    ->required(),
                Select::make('item_id')
                    ->relationship('item', 'name')
                    ->required(),
                TextInput::make('real_stock_id')
                    ->numeric(),
                Select::make('location_id')
                    ->relationship('location', 'name'),
                TextInput::make('zone_code'),
                TextInput::make('walking_order')
                    ->numeric(),
                TextInput::make('distance_from_previous')
                    ->numeric(),
                TextInput::make('ordered_qty')
                    ->required()
                    ->numeric()
                    ->default(0),
                Select::make('ordered_qty_type')
                    ->options(['CASE' => 'C a s e', 'PIECE' => 'P i e c e', 'CARTON' => 'C a r t o n'])
                    ->required(),
                TextInput::make('planned_qty')
                    ->required()
                    ->numeric(),
                Select::make('planned_qty_type')
                    ->options(['CASE' => 'C a s e', 'PIECE' => 'P i e c e', 'CARTON' => 'C a r t o n'])
                    ->required(),
                TextInput::make('picked_qty')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('shortage_allocated_qty')
                    ->required()
                    ->numeric()
                    ->default(0),
                Select::make('shortage_allocated_qty_type')
                    ->options(['CASE' => 'C a s e', 'PIECE' => 'P i e c e', 'CARTON' => 'C a r t o n']),
                Toggle::make('is_ready_to_shipment')
                    ->required(),
                DateTimePicker::make('shipment_ready_at'),
                Select::make('picked_qty_type')
                    ->options(['CASE' => 'C a s e', 'PIECE' => 'P i e c e', 'CARTON' => 'C a r t o n'])
                    ->required(),
                TextInput::make('shortage_qty')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('has_physical_shortage'),
                Toggle::make('has_soft_shortage'),
                Toggle::make('has_shortage'),
                Select::make('status')
                    ->options([
            'PENDING' => 'P e n d i n g',
            'PICKING' => 'P i c k i n g',
            'COMPLETED' => 'C o m p l e t e d',
            'SHORTAGE' => 'S h o r t a g e',
        ])
                    ->default('PENDING'),
                DateTimePicker::make('picked_at'),
                TextInput::make('picker_id')
                    ->numeric(),
            ]);
    }
}
