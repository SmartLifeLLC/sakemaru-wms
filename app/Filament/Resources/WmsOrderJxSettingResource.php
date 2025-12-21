<?php

namespace App\Filament\Resources;

use App\Enums\EMenu;
use App\Filament\Resources\WmsOrderJxSettingResource\Pages;
use App\Models\WmsOrderJxSetting;
use App\Services\JX\JxClient;
use App\Services\JX\JxDocumentReceiver;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

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
                        TextInput::make('jx_client_id')
                            ->label('JXクライアントID')
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

                Section::make('送信元情報（ヘッダー用）')
                    ->columns(2)
                    ->schema([
                        TextInput::make('sender_trading_code')
                            ->label('統一取引先コード')
                            ->maxLength(12)
                            ->helperText('12桁'),
                        TextInput::make('sender_station_code')
                            ->label('ステーションコード')
                            ->maxLength(6)
                            ->helperText('6桁'),
                        TextInput::make('sender_name')
                            ->label('企業名（半角カナ）')
                            ->maxLength(15)
                            ->helperText('15文字以内'),
                        TextInput::make('sender_office_name')
                            ->label('事業所名（半角カナ）')
                            ->maxLength(10)
                            ->helperText('10文字以内'),
                    ]),

                Section::make('送信先情報（ヘッダー用）')
                    ->columns(2)
                    ->schema([
                        TextInput::make('receiver_trading_code')
                            ->label('統一取引先コード')
                            ->maxLength(12)
                            ->helperText('12桁'),
                        TextInput::make('receiver_station_code')
                            ->label('ステーションコード')
                            ->maxLength(6)
                            ->helperText('6桁'),
                    ]),

                Section::make('データ種別コード')
                    ->columns(2)
                    ->schema([
                        TextInput::make('send_document_type')
                            ->label('送信種別コード')
                            ->maxLength(2)
                            ->default('91')
                            ->helperText('2桁（例: 91=発注送信）'),
                        TextInput::make('receive_document_type')
                            ->label('受信種別コード')
                            ->maxLength(2)
                            ->default('90')
                            ->helperText('2桁（例: 90=発注受信）'),
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

                Section::make('送信テスト')
                    ->schema([
                        FileUpload::make('test_file_path')
                            ->label('テスト送信用ファイル')
                            ->disk('s3')
                            ->directory('jx-test-files')
                            ->visibility('private')
                            ->acceptedFileTypes(['text/plain', 'text/xml', 'application/xml'])
                            ->maxSize(10240)
                            ->helperText('テスト送信用のファイルをアップロード（最大10MB）。S3の ' . config('filesystems.disks.s3.prefix', '') . ' prefix配下に保存されます。'),
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
                IconColumn::make('test_file_path')
                    ->label('テストファイル')
                    ->boolean()
                    ->getStateUsing(fn (WmsOrderJxSetting $record) => (bool) $record->test_file_path)
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
                Action::make('testSend')
                    ->label('テスト送信')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('JXテスト送信')
                    ->modalDescription('このJX設定でテスト送信を実行しますか？')
                    ->modalSubmitActionLabel('送信')
                    ->visible(fn (WmsOrderJxSetting $record) => $record->test_file_path && $record->is_active)
                    ->action(function (WmsOrderJxSetting $record) {
                        try {
                            // テストファイルを取得（S3 prefixは自動適用）
                            $fileContent = Storage::disk('s3')->get($record->test_file_path);
                            if (! $fileContent) {
                                Notification::make()
                                    ->title('エラー')
                                    ->body('テストファイルが見つかりません')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            // JX送信実行（ヘッダー・フッター自動付与）
                            $client = new JxClient($record);
                            $result = $client->putDocumentWithWrapper($fileContent, $record->send_document_type ?? '91', 'SecondGenEDI');

                            if ($result->succeeded()) {
                                Notification::make()
                                    ->title('送信成功')
                                    ->body("メッセージID: {$result->messageId}")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('送信失敗')
                                    ->body($result->error ?? '不明なエラー')
                                    ->danger()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('receive')
                    ->label('受信実行')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('JXドキュメント受信')
                    ->modalDescription('このJX設定で全ての待機中ドキュメントを受信しますか？（S3に保存）')
                    ->modalSubmitActionLabel('受信開始')
                    ->visible(fn (WmsOrderJxSetting $record) => $record->is_active)
                    ->action(function (WmsOrderJxSetting $record) {
                        try {
                            $receiver = new JxDocumentReceiver($record);
                            // 本番用: S3ストレージを使用
                            $receiver->setStorageDisk('s3');

                            $documents = $receiver->receiveAll();

                            if ($documents->isEmpty()) {
                                Notification::make()
                                    ->title('受信完了')
                                    ->body('受信可能なドキュメントはありません')
                                    ->info()
                                    ->send();

                                return;
                            }

                            $body = "受信件数: {$documents->count()}件\n\n";
                            foreach ($documents->take(5) as $doc) {
                                $body .= "- {$doc->messageId} ({$doc->documentType})\n";
                            }
                            if ($documents->count() > 5) {
                                $body .= "...他 " . ($documents->count() - 5) . "件";
                            }

                            Notification::make()
                                ->title('受信成功')
                                ->body($body)
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('testReceive')
                    ->label('テスト受信')
                    ->icon('heroicon-o-beaker')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('JXテスト受信')
                    ->modalDescription('このJX設定でドキュメント受信をテストしますか？（ローカルストレージに保存）')
                    ->modalSubmitActionLabel('テスト受信')
                    ->visible(fn (WmsOrderJxSetting $record) => $record->is_active && app()->environment('local', 'testing', 'staging'))
                    ->action(function (WmsOrderJxSetting $record) {
                        try {
                            $receiver = new JxDocumentReceiver($record);
                            // テスト用: localストレージを使用
                            $receiver->setStorageDisk('local');

                            $document = $receiver->receiveSingle();

                            if ($document === null) {
                                Notification::make()
                                    ->title('受信完了')
                                    ->body('受信可能なドキュメントはありません')
                                    ->info()
                                    ->send();

                                return;
                            }

                            $body = "メッセージID: {$document->messageId}\n";
                            $body .= "ドキュメントタイプ: {$document->documentType}\n";
                            $body .= "サイズ: {$document->getDataSize()} bytes\n";
                            $body .= "保存先: {$document->savedPath}\n";
                            $body .= "確認: " . ($document->confirmed ? 'OK' : 'NG');

                            Notification::make()
                                ->title('テスト受信成功')
                                ->body($body)
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
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
