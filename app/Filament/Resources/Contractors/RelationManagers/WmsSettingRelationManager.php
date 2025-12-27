<?php

namespace App\Filament\Resources\Contractors\RelationManagers;

use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderFtpSetting;
use App\Models\WmsOrderJxSetting;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WmsSettingRelationManager extends RelationManager
{
    protected static string $relationship = 'wmsSetting';

    protected static ?string $title = 'WMS送信設定';

    protected static ?string $modelLabel = '送信設定';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('transmission_type')
                    ->label('送信方式')
                    ->options(collect(TransmissionType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                    ->required()
                    ->live()
                    ->default(TransmissionType::MANUAL_CSV->value),

                Select::make('wms_order_jx_setting_id')
                    ->label('JX設定')
                    ->options(fn () => WmsOrderJxSetting::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->nullable()
                    ->visible(fn (Get $get) => $get('transmission_type') === TransmissionType::JX_FINET->value)
                    ->required(fn (Get $get) => $get('transmission_type') === TransmissionType::JX_FINET->value),

                Select::make('wms_order_ftp_setting_id')
                    ->label('FTP設定')
                    ->options(fn () => WmsOrderFtpSetting::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->nullable()
                    ->visible(fn (Get $get) => $get('transmission_type') === TransmissionType::FTP->value)
                    ->required(fn (Get $get) => $get('transmission_type') === TransmissionType::FTP->value),

                Select::make('supply_warehouse_id')
                    ->label('供給倉庫')
                    ->options(fn () => Warehouse::pluck('name', 'id'))
                    ->searchable()
                    ->nullable()
                    ->visible(fn (Get $get) => $get('transmission_type') === TransmissionType::INTERNAL->value)
                    ->required(fn (Get $get) => $get('transmission_type') === TransmissionType::INTERNAL->value)
                    ->helperText('倉庫間移動の場合、供給元の倉庫を選択'),

                TextInput::make('transmission_time')
                    ->label('送信時刻')
                    ->type('time')
                    ->nullable()
                    ->helperText('指定しない場合は手動送信'),

                Fieldset::make('送信曜日')
                    ->schema([
                        Toggle::make('is_transmission_mon')->label('月')->inline(false),
                        Toggle::make('is_transmission_tue')->label('火')->inline(false),
                        Toggle::make('is_transmission_wed')->label('水')->inline(false),
                        Toggle::make('is_transmission_thu')->label('木')->inline(false),
                        Toggle::make('is_transmission_fri')->label('金')->inline(false),
                        Toggle::make('is_transmission_sat')->label('土')->inline(false),
                        Toggle::make('is_transmission_sun')->label('日')->inline(false),
                    ])
                    ->columns(7),

                Toggle::make('is_auto_transmission')
                    ->label('自動送信')
                    ->default(false)
                    ->helperText('ONにすると指定時刻・曜日に自動送信されます'),

                TextInput::make('format_strategy_class')
                    ->label('フォーマット戦略クラス')
                    ->maxLength(255)
                    ->nullable()
                    ->visible(fn (Get $get) => in_array($get('transmission_type'), [
                        TransmissionType::JX_FINET->value,
                        TransmissionType::FTP->value,
                    ])),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transmission_type')
            ->columns([
                TextColumn::make('transmission_type')
                    ->label('送信方式')
                    ->badge()
                    ->formatStateUsing(fn (TransmissionType $state): string => $state->label())
                    ->color(fn (TransmissionType $state): string => match ($state) {
                        TransmissionType::JX_FINET => 'info',
                        TransmissionType::FTP => 'success',
                        TransmissionType::MANUAL_CSV => 'gray',
                        TransmissionType::INTERNAL => 'warning',
                    }),

                TextColumn::make('jxSetting.name')
                    ->label('JX設定')
                    ->placeholder('-'),

                TextColumn::make('supplyWarehouse.name')
                    ->label('供給倉庫')
                    ->placeholder('-'),

                TextColumn::make('transmission_time')
                    ->label('送信時刻')
                    ->placeholder('手動'),

                TextColumn::make('transmission_days_label')
                    ->label('送信曜日')
                    ->placeholder('-'),

                IconColumn::make('is_auto_transmission')
                    ->label('自動送信')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['contractor_id'] = $this->getOwnerRecord()->id;
                        return $data;
                    })
                    ->visible(fn () => !$this->getOwnerRecord()->wmsSetting()->exists()),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
