<?php

namespace App\Filament\Resources\WaveSettings\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class WaveSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Wave Configuration')
                    ->description('Configure wave generation settings for delivery course combinations')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('name')
                            ->label('Wave Name')
                            ->maxLength(255)
                            ->nullable(),

                        Select::make('delivery_course_id')
                            ->label('配送コース')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('delivery_courses as dc')
                                    ->join('warehouses as w', 'dc.warehouse_id', '=', 'w.id')
                                    ->selectRaw("dc.id, CONCAT('[', w.name, '] ', dc.code, ' - ', dc.name) as label")
                                    ->orderBy('w.name')
                                    ->orderBy('dc.code')
                                    ->pluck('label', 'dc.id');
                            })
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                return DB::connection('sakemaru')
                                    ->table('delivery_courses as dc')
                                    ->join('warehouses as w', 'dc.warehouse_id', '=', 'w.id')
                                    ->where(function ($query) use ($search) {
                                        $query->where('dc.name', 'like', "%{$search}%")
                                            ->orWhere('dc.code', 'like', "%{$search}%")
                                            ->orWhere('w.name', 'like', "%{$search}%");
                                    })
                                    ->selectRaw("dc.id, CONCAT('[', w.name, '] ', dc.code, ' - ', dc.name) as label")
                                    ->orderBy('w.name')
                                    ->orderBy('dc.code')
                                    ->pluck('label', 'dc.id')
                                    ->toArray();
                            })
                            ->live(),

                        Placeholder::make('warehouse_display')
                            ->label('倉庫')
                            ->content(function (callable $get): string {
                                $courseId = $get('delivery_course_id');

                                if (! $courseId) {
                                    return '配送コースを選択してください';
                                }

                                $warehouse = DB::connection('sakemaru')
                                    ->table('delivery_courses as dc')
                                    ->join('warehouses as w', 'dc.warehouse_id', '=', 'w.id')
                                    ->where('dc.id', $courseId)
                                    ->selectRaw("CONCAT(w.code, ' - ', w.name) as label")
                                    ->value('label');

                                return $warehouse ?? '不明';
                            }),

                        TimePicker::make('picking_start_time')
                            ->label('Picking Start Time')
                            ->seconds(false)
                            ->nullable(),

                        TimePicker::make('picking_deadline_time')
                            ->label('Picking Deadline Time')
                            ->seconds(false)
                            ->nullable(),

                        Select::make('creator_id')
                            ->label('Creator')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('users')
                                    ->pluck('name', 'id');
                            })
                            ->default(fn () => auth()->id())
                            ->required()
                            ->disabled()
                            ->dehydrated(),

                        Select::make('last_updater_id')
                            ->label('Last Updater')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('users')
                                    ->pluck('name', 'id');
                            })
                            ->default(fn () => auth()->id())
                            ->required()
                            ->disabled()
                            ->dehydrated(),
                    ])
                    ->columns(2),
            ]);
    }
}
