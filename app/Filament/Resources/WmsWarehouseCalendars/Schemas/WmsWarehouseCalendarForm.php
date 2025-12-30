<?php

namespace App\Filament\Resources\WmsWarehouseCalendars\Schemas;

use App\Models\Sakemaru\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WmsWarehouseCalendarForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('休日設定')
                ->schema([
                    Select::make('warehouse_id')
                        ->label('倉庫')
                        ->options(fn () => Warehouse::pluck('name', 'id')->toArray())
                        ->searchable()
                        ->required(),

                    DatePicker::make('target_date')
                        ->label('対象日')
                        ->required()
                        ->native(false)
                        ->displayFormat('Y-m-d'),

                    Toggle::make('is_holiday')
                        ->label('休日')
                        ->default(true)
                        ->helperText('ONで休日、OFFで営業日'),

                    TextInput::make('holiday_reason')
                        ->label('休日理由')
                        ->maxLength(255)
                        ->placeholder('例: 年末年始休業、臨時休業など'),

                    Toggle::make('is_manual_override')
                        ->label('手動設定')
                        ->default(true)
                        ->helperText('自動生成された定休日を手動で上書きする場合はON'),
                ])
                ->columns(2),
        ]);
    }
}
