<?php

namespace App\Filament\Resources\WmsContractorHolidays\Schemas;

use App\Models\Sakemaru\Contractor;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WmsContractorHolidayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('発注先休日設定')
                ->schema([
                    Select::make('contractor_id')
                        ->label('発注先')
                        ->options(fn () => Contractor::pluck('name', 'id')->toArray())
                        ->searchable()
                        ->required(),

                    DatePicker::make('holiday_date')
                        ->label('休業日')
                        ->required()
                        ->native(false)
                        ->displayFormat('Y-m-d'),

                    TextInput::make('reason')
                        ->label('休業理由')
                        ->maxLength(100)
                        ->placeholder('例: 臨時休業、棚卸し、年末年始など'),
                ])
                ->columns(2),
        ]);
    }
}
