<?php

namespace App\Filament\Resources;

use App\Enums\EMenu;
use App\Filament\Resources\WmsOrderJxSettingResource\Pages;
use App\Models\WmsOrderJxSetting;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WmsOrderJxSettingResource extends Resource
{
    protected static ?string $model = WmsOrderJxSetting::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-server';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_ORDER_JX_SETTINGS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_ORDER_JX_SETTINGS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_ORDER_JX_SETTINGS->sort();
    }

    public static function getModelLabel(): string
    {
        return 'JX接続設定';
    }

    public static function getPluralModelLabel(): string
    {
        return 'JX接続設定';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('設定名')
                            ->required()
                            ->maxLength(100),
                        Checkbox::make('is_active')
                            ->label('有効')
                            ->default(true),
                    ]),

                Section::make('JX接続情報')
                    ->columns(2)
                    ->schema([
                        TextInput::make('van_center')
                            ->label('VANセンター')
                            ->maxLength(50),
                        TextInput::make('server_id')
                            ->label('サーバーID')
                            ->maxLength(50),
                        TextInput::make('endpoint_url')
                            ->label('エンドポイントURL')
                            ->url()
                            ->maxLength(255),
                        TextInput::make('jx_from')
                            ->label('JX From')
                            ->maxLength(50),
                        TextInput::make('jx_to')
                            ->label('JX To')
                            ->maxLength(50),
                    ]),

                Section::make('認証情報')
                    ->columns(2)
                    ->schema([
                        Checkbox::make('is_basic_auth')
                            ->label('Basic認証を使用')
                            ->default(false)
                            ->live(),
                        TextInput::make('basic_user_id')
                            ->label('Basic認証ユーザーID')
                            ->maxLength(100)
                            ->visible(fn ($get) => $get('is_basic_auth')),
                        TextInput::make('basic_user_pw')
                            ->label('Basic認証パスワード')
                            ->password()
                            ->maxLength(255)
                            ->visible(fn ($get) => $get('is_basic_auth'))
                            ->dehydrateStateUsing(fn ($state) => $state ?: null)
                            ->dehydrated(fn ($state) => filled($state)),
                    ]),

                Section::make('SSL設定')
                    ->schema([
                        TextInput::make('ssl_certification_file')
                            ->label('SSL証明書ファイルパス')
                            ->maxLength(255)
                            ->helperText('SSL証明書ファイルのパスを指定'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(25)
            ->striped()
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('name')
                    ->label('設定名')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('van_center')
                    ->label('VANセンター')
                    ->searchable(),
                TextColumn::make('endpoint_url')
                    ->label('エンドポイントURL')
                    ->limit(50)
                    ->tooltip(fn ($state) => $state),
                IconColumn::make('is_basic_auth')
                    ->label('Basic認証')
                    ->boolean()
                    ->alignCenter(),
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
                //
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
            'index' => Pages\ListWmsOrderJxSettings::route('/'),
            'create' => Pages\CreateWmsOrderJxSetting::route('/create'),
            'edit' => Pages\EditWmsOrderJxSetting::route('/{record}/edit'),
        ];
    }
}
