<?php

namespace App\Filament\Resources\WmsContractorSettings\Schemas;

use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderJxSetting;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WmsContractorSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('基本設定')
                ->schema([
                    Select::make('contractor_id')
                        ->label('発注先')
                        ->options(fn () => Contractor::pluck('name', 'id')->toArray())
                        ->searchable()
                        ->required()
                        ->unique(ignoreRecord: true),

                    Select::make('transmission_type')
                        ->label('送信方式')
                        ->options(collect(TransmissionType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()])->toArray())
                        ->default(TransmissionType::MANUAL_CSV->value)
                        ->required()
                        ->live(),

                    Select::make('wms_order_jx_setting_id')
                        ->label('JX設定')
                        ->options(fn () => WmsOrderJxSetting::where('is_active', true)->pluck('name', 'id')->toArray())
                        ->searchable()
                        ->placeholder('JX設定を選択')
                        ->visible(fn ($get) => $get('transmission_type') === TransmissionType::JX_FINET->value)
                        ->required(fn ($get) => $get('transmission_type') === TransmissionType::JX_FINET->value),

                    Select::make('transmission_contractor_id')
                        ->label('送信先発注先')
                        ->options(fn () => Contractor::pluck('name', 'id')->toArray())
                        ->searchable()
                        ->placeholder('別の発注先経由で送信する場合に指定')
                        ->helperText('この発注先の発注データを別の発注先の設定で送信する場合に指定してください（例: カナカン系の発注先はカナカンの設定で送信）'),

                    Select::make('supply_warehouse_id')
                        ->label('供給倉庫')
                        ->options(fn () => Warehouse::pluck('name', 'id')->toArray())
                        ->searchable()
                        ->placeholder('倉庫を選択')
                        ->visible(fn ($get) => $get('transmission_type') === TransmissionType::INTERNAL->value)
                        ->required(fn ($get) => $get('transmission_type') === TransmissionType::INTERNAL->value)
                        ->helperText('倉庫間移動の場合、在庫を供給する倉庫を指定してください'),
                ])
                ->columns(2),

            Section::make('自動送信設定')
                ->schema([
                    Toggle::make('is_auto_transmission')
                        ->label('自動送信を有効にする')
                        ->helperText('有効にすると、指定した曜日・時刻に自動で発注データを送信します')
                        ->live(),

                    TimePicker::make('transmission_time')
                        ->label('送信時刻')
                        ->seconds(false)
                        ->visible(fn ($get) => $get('is_auto_transmission'))
                        ->required(fn ($get) => $get('is_auto_transmission')),

                    TextInput::make('auto_order_generation_time')
                        ->label('自動発注生成時刻')
                        ->placeholder('HH:MM')
                        ->maxLength(5)
                        ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                        ->helperText('仕入先別の自動発注候補生成時刻（HH:MM形式）。未設定の場合は自動実行対象外'),

                    Fieldset::make('送信曜日')
                        ->schema([
                            Checkbox::make('is_transmission_sun')
                                ->label('日')
                                ->inline(),

                            Checkbox::make('is_transmission_mon')
                                ->label('月')
                                ->inline(),

                            Checkbox::make('is_transmission_tue')
                                ->label('火')
                                ->inline(),

                            Checkbox::make('is_transmission_wed')
                                ->label('水')
                                ->inline(),

                            Checkbox::make('is_transmission_thu')
                                ->label('木')
                                ->inline(),

                            Checkbox::make('is_transmission_fri')
                                ->label('金')
                                ->inline(),

                            Checkbox::make('is_transmission_sat')
                                ->label('土')
                                ->inline(),
                        ])
                        ->columns(7)
                        ->visible(fn ($get) => $get('is_auto_transmission')),
                ]),
        ]);
    }
}
