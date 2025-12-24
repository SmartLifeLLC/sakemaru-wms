<?php

namespace App\Filament\Resources\Contractors\RelationManagers;

use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderFtpSetting;
use App\Models\WmsOrderJxSetting;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WarehouseContractorSettingsRelationManager extends RelationManager
{
    protected static string $relationship = 'warehouseContractorSettings';

    protected static ?string $title = '外部データ送信設定';

    protected static ?string $modelLabel = '送信設定';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('warehouse_id')
                    ->label('倉庫')
                    ->options(fn () => Warehouse::where('is_active', true)->pluck('name', 'id'))
                    ->required()
                    ->searchable()
                    ->unique(ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        return $rule->where('contractor_id', $this->getOwnerRecord()->id);
                    }),

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

                TimePicker::make('transmission_time')
                    ->label('送信時刻')
                    ->seconds(false)
                    ->nullable()
                    ->helperText('指定しない場合は手動送信'),

                CheckboxList::make('transmission_days')
                    ->label('送信曜日')
                    ->options([
                        1 => '月曜日',
                        2 => '火曜日',
                        3 => '水曜日',
                        4 => '木曜日',
                        5 => '金曜日',
                        6 => '土曜日',
                        0 => '日曜日',
                    ])
                    ->columns(4)
                    ->helperText('チェックした曜日に自動送信します'),

                Toggle::make('is_auto_transmission')
                    ->label('自動送信')
                    ->default(false)
                    ->helperText('ONにすると指定時刻・曜日に自動送信されます'),

                Toggle::make('is_active')
                    ->label('有効')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('warehouse.name')
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('transmission_type')
                    ->label('送信方式')
                    ->badge()
                    ->formatStateUsing(fn (TransmissionType $state): string => $state->label())
                    ->color(fn (TransmissionType $state): string => match ($state) {
                        TransmissionType::JX_FINET => 'info',
                        TransmissionType::FTP => 'success',
                        TransmissionType::MANUAL_CSV => 'gray',
                    }),

                TextColumn::make('jxSetting.name')
                    ->label('JX設定')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('ftpSetting.name')
                    ->label('FTP設定')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('transmission_time')
                    ->label('送信時刻')
                    ->time('H:i')
                    ->placeholder('手動'),

                TextColumn::make('transmission_days_label')
                    ->label('送信曜日')
                    ->placeholder('-'),

                IconColumn::make('is_auto_transmission')
                    ->label('自動送信')
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label('有効')
                    ->boolean(),
            ])
            ->defaultSort('warehouse_id')
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
