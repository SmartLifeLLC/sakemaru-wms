<?php

namespace App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\Schemas;

use App\Models\WmsBuyerDeliveryCourseSwitchSetting;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class WmsBuyerDeliveryCourseSwitchSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('配送コース切替設定')
                    ->description('得意先の配送コースを指定時刻に自動切替する設定')
                    ->schema([
                        Select::make('buyer_id')
                            ->label('得意先')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('buyers as b')
                                    ->join('partners as p', 'b.partner_id', '=', 'p.id')
                                    ->where('p.is_active', true)
                                    ->selectRaw("b.id, CONCAT('[', p.code, '] ', p.name) as label")
                                    ->orderBy('p.code')
                                    ->pluck('label', 'b.id');
                            })
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                return DB::connection('sakemaru')
                                    ->table('buyers as b')
                                    ->join('partners as p', 'b.partner_id', '=', 'p.id')
                                    ->where('p.is_active', true)
                                    ->where(function ($query) use ($search) {
                                        $query->where('p.name', 'like', "%{$search}%")
                                            ->orWhere('p.code', 'like', "%{$search}%");
                                    })
                                    ->selectRaw("b.id, CONCAT('[', p.code, '] ', p.name) as label")
                                    ->orderBy('p.code')
                                    ->pluck('label', 'b.id')
                                    ->toArray();
                            }),

                        Select::make('switch_time')
                            ->label('切替時刻')
                            ->options(function () {
                                $options = [];
                                for ($hour = 0; $hour < 24; $hour++) {
                                    foreach ([0, 15, 30, 45] as $minute) {
                                        $time = sprintf('%02d:%02d:00', $hour, $minute);
                                        $display = sprintf('%02d:%02d', $hour, $minute);
                                        $options[$time] = $display;
                                    }
                                }

                                return $options;
                            })
                            ->required()
                            ->rules([WmsBuyerDeliveryCourseSwitchSetting::switchTimeRule()])
                            ->helperText('15分単位で設定可能'),

                        Select::make('to_delivery_course_id')
                            ->label('切替先配送コース')
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
                            }),
                    ])
                    ->columns(2),
            ]);
    }
}
