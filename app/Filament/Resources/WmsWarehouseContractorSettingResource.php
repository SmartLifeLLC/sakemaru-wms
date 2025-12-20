<?php

namespace App\Filament\Resources;

use App\Enums\AutoOrder\TransmissionType;
use App\Enums\EMenu;
use App\Filament\Resources\WmsWarehouseContractorSettingResource\Pages;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderFtpSetting;
use App\Models\WmsOrderJxSetting;
use App\Models\WmsWarehouseContractorSetting;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsWarehouseContractorSettingResource extends Resource
{
    protected static ?string $model = WmsWarehouseContractorSetting::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_WAREHOUSE_CONTRACTOR_SETTINGS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_WAREHOUSE_CONTRACTOR_SETTINGS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_WAREHOUSE_CONTRACTOR_SETTINGS->sort();
    }

    public static function getModelLabel(): string
    {
        return '発注先接続設定';
    }

    public static function getPluralModelLabel(): string
    {
        return '発注先接続設定';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->columns(2)
                    ->schema([
                        Select::make('warehouse_id')
                            ->label('倉庫')
                            ->options(
                                Warehouse::where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                            )
                            ->required()
                            ->searchable(),
                        Select::make('contractor_id')
                            ->label('発注先')
                            ->options(
                                Contractor::orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"])
                            )
                            ->required()
                            ->searchable(),
                        Select::make('transmission_type')
                            ->label('送信方式')
                            ->options(collect(TransmissionType::cases())->mapWithKeys(
                                fn ($type) => [$type->value => $type->label()]
                            ))
                            ->required()
                            ->default(TransmissionType::MANUAL_CSV->value)
                            ->live(),
                        Checkbox::make('is_active')
                            ->label('有効')
                            ->default(true),
                    ]),

                Section::make('JX-FINET設定')
                    ->visible(fn ($get) => $get('transmission_type') === TransmissionType::JX_FINET->value)
                    ->schema([
                        Select::make('wms_order_jx_setting_id')
                            ->label('JX接続設定')
                            ->options(
                                WmsOrderJxSetting::where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                            )
                            ->searchable()
                            ->helperText('JX-FINET送信に使用する接続設定を選択'),
                    ]),

                Section::make('FTP設定')
                    ->visible(fn ($get) => $get('transmission_type') === TransmissionType::FTP->value)
                    ->schema([
                        Select::make('wms_order_ftp_setting_id')
                            ->label('FTP接続設定')
                            ->options(
                                WmsOrderFtpSetting::where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                            )
                            ->searchable()
                            ->helperText('FTP送信に使用する接続設定を選択'),
                    ]),

                Section::make('フォーマット設定')
                    ->schema([
                        TextInput::make('format_strategy_class')
                            ->label('フォーマット戦略クラス')
                            ->maxLength(255)
                            ->helperText('発注データのフォーマット戦略クラス名（例: App\\Services\\Order\\Strategies\\DefaultFormatStrategy）'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(25)
            ->striped()
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['warehouse', 'contractor', 'jxSetting']))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($record) => "[{$record->contractor?->code}] {$record->contractor?->name}"),
                TextColumn::make('transmission_type')
                    ->label('送信方式')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->color(fn ($state) => match ($state) {
                        TransmissionType::JX_FINET => 'success',
                        TransmissionType::FTP => 'info',
                        TransmissionType::MANUAL_CSV => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('jxSetting.name')
                    ->label('JX設定')
                    ->placeholder('-'),
                IconColumn::make('is_active')
                    ->label('有効')
                    ->boolean()
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->options(
                        Warehouse::where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                    ),
                SelectFilter::make('transmission_type')
                    ->label('送信方式')
                    ->options(collect(TransmissionType::cases())->mapWithKeys(
                        fn ($type) => [$type->value => $type->label()]
                    )),
                SelectFilter::make('is_active')
                    ->label('有効')
                    ->options([
                        '1' => '有効のみ',
                        '0' => '無効のみ',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWmsWarehouseContractorSettings::route('/'),
            'create' => Pages\CreateWmsWarehouseContractorSetting::route('/create'),
            'edit' => Pages\EditWmsWarehouseContractorSetting::route('/{record}/edit'),
        ];
    }
}
