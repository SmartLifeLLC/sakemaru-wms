<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\WmsPicker;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

class TestDataGenerator extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected string $view = 'filament.pages.test-data-generator';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::TEST_DATA_GENERATOR->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::TEST_DATA_GENERATOR->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::TEST_DATA_GENERATOR->sort();
    }

    public static function canAccess(): bool
    {
        // Only show in non-production environments
        return app()->environment(['local', 'development', 'staging']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetTestData')
                ->label('テストデータリセット')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('テストデータをリセット')
                ->modalDescription('WMS関連のテストデータを削除します。この操作は取り消せません。')
                ->modalWidth('xl')
                ->form([
                    Select::make('warehouse_id')
                        ->label('倉庫')
                        ->helperText('指定した倉庫のデータのみ削除します。未選択の場合は全倉庫が対象になります。')
                        ->options(\App\Models\Sakemaru\Warehouse::pluck('name', 'id'))
                        ->searchable(),

                    Toggle::make('delete_waves')
                        ->label('Wave・ピッキングタスク・予約データを削除')
                        ->default(true),

                    Toggle::make('delete_earnings')
                        ->label('売上データ（Earnings）を削除')
                        ->helperText('BoozeCore経由で作成された売上伝票を削除')
                        ->default(false),

                    Toggle::make('delete_stocks')
                        ->label('在庫データを削除')
                        ->helperText('real_stocks, wms_real_stocksを削除')
                        ->default(false),

                    Toggle::make('delete_locations')
                        ->label('Locationを削除')
                        ->default(false),

                    Toggle::make('delete_floors')
                        ->label('Floorを削除')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    try {
                        $params = [];

                        if (!empty($data['warehouse_id'])) {
                            $params['--warehouse-id'] = $data['warehouse_id'];
                        }

                        if ($data['delete_waves'] ?? false) {
                            $params['--waves'] = true;
                        }

                        if ($data['delete_earnings'] ?? false) {
                            $params['--earnings'] = true;
                        }

                        if ($data['delete_stocks'] ?? false) {
                            $params['--stocks'] = true;
                        }

                        if ($data['delete_locations'] ?? false) {
                            $params['--locations'] = true;
                        }

                        if ($data['delete_floors'] ?? false) {
                            $params['--floors'] = true;
                        }

                        $exitCode = Artisan::call('testdata:reset', $params);
                        $output = Artisan::output();

                        if ($exitCode === 0) {
                            Notification::make()
                                ->title('テストデータをリセットしました')
                                ->body('指定されたデータの削除が完了しました。')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($output)
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

            Action::make('generateWaveSettings')
                ->label('Wave設定生成')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Wave設定を生成')
                ->modalDescription('全倉庫・配送コース別に1時間単位のWave設定を生成します。')
                ->form([
                    Select::make('warehouse_id')
                        ->label('倉庫')
                        ->helperText('未選択の場合、全ての有効な倉庫に対して生成します。')
                        ->options(\App\Models\Sakemaru\Warehouse::where('is_active', true)->pluck('name', 'id'))
                        ->searchable(),
                    Toggle::make('reset')
                        ->label('既存設定をリセット')
                        ->helperText('既存のWave設定を削除してから生成します。')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    try {
                        $params = [];

                        if (!empty($data['warehouse_id'])) {
                            $params['--warehouse-id'] = $data['warehouse_id'];
                        }

                        if ($data['reset'] ?? false) {
                            $params['--reset'] = true;
                        }

                        $exitCode = Artisan::call('testdata:wave-settings', $params);
                        $output = Artisan::output();

                        if ($exitCode === 0) {
                            Notification::make()
                                ->title('Wave設定を生成しました')
                                ->body('1時間単位のWave設定の生成が完了しました。')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($output)
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

            Action::make('generateWmsData')
                ->label('WMSマスタ生成')
                ->icon('heroicon-o-cube')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('WMSマスタデータを生成')
                ->modalDescription('Floor、Locationのマスタデータを生成します。在庫データは生成しません。')
                ->modalWidth('2xl')
                ->form([
                    Select::make('warehouse_id')
                        ->label('倉庫')
                        ->options(\App\Models\Sakemaru\Warehouse::pluck('name', 'id'))
                        ->default(991)
                        ->required()
                        ->searchable(),

                    Repeater::make('floors')
                        ->label('フロア設定')
                        ->schema([
                            TextInput::make('number')
                                ->label('フロア番号')
                                ->helperText('正の数: 1F, 2F... / 負の数: B1F(-1), B2F(-2)...')
                                ->numeric()
                                ->required()
                                ->default(1),
                            TextInput::make('name')
                                ->label('フロア名')
                                ->required()
                                ->default('1F'),
                        ])
                        ->columns(2)
                        ->defaultItems(2)
                        ->minItems(1)
                        ->maxItems(10)
                        ->addActionLabel('フロアを追加')
                        ->collapsible()
                        ->itemLabel(function (array $state): ?string {
                            return isset($state['name']) ? "フロア: {$state['name']}" : null;
                        })
                        ->default([
                            ['number' => 1, 'name' => '1F'],
                            ['number' => 2, 'name' => '2F'],
                        ]),

                    TextInput::make('case_locations')
                        ->label('ケースロケーション数/フロア')
                        ->helperText('各フロアに作成するケース専用ロケーション数')
                        ->numeric()
                        ->default(10)
                        ->required()
                        ->minValue(0)
                        ->maxValue(50),

                    TextInput::make('piece_locations')
                        ->label('バラロケーション数/フロア')
                        ->helperText('各フロアに作成するバラ専用ロケーション数')
                        ->numeric()
                        ->default(10)
                        ->required()
                        ->minValue(0)
                        ->maxValue(50),

                    TextInput::make('both_locations')
                        ->label('ケース+バラロケーション数/フロア')
                        ->helperText('各フロアに作成するケース+バラ併用ロケーション数')
                        ->numeric()
                        ->default(5)
                        ->required()
                        ->minValue(0)
                        ->maxValue(50),
                ])
                ->action(function (array $data): void {
                    try {
                        $params = [
                            '--warehouse-id' => $data['warehouse_id'],
                            '--case-locations' => $data['case_locations'],
                            '--piece-locations' => $data['piece_locations'],
                            '--both-locations' => $data['both_locations'],
                            '--floors' => array_map(
                                fn($f) => "{$f['number']}|{$f['name']}",
                                $data['floors']
                            ),
                        ];

                        $exitCode = Artisan::call('testdata:master', $params);
                        $output = Artisan::output();

                        if ($exitCode === 0) {
                            $totalFloors = count($data['floors']);
                            $totalLocationsPerFloor = $data['case_locations'] + $data['piece_locations'] + $data['both_locations'];

                            Notification::make()
                                ->title('WMSマスタデータを生成しました')
                                ->body("フロア{$totalFloors}件、ロケーション約" . ($totalFloors * $totalLocationsPerFloor) . "件の生成が完了しました。")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($output)
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

            Action::make('generateStocks')
                ->label('在庫データ生成')
                ->icon('heroicon-o-archive-box')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('在庫データを生成')
                ->modalDescription('指定されたロケーションに在庫データを生成します。')
                ->modalWidth('xl')
                ->form([
                    Select::make('warehouse_id')
                        ->label('倉庫')
                        ->options(\App\Models\Sakemaru\Warehouse::pluck('name', 'id'))
                        ->default(991)
                        ->required()
                        ->searchable()
                        ->reactive(),

                    Select::make('locations')
                        ->label('ロケーション指定')
                        ->helperText('在庫を生成するロケーションを選択（複数選択可）')
                        ->options(function (Get $get) {
                            $warehouseId = $get('warehouse_id');
                            if (!$warehouseId) {
                                return [];
                            }
                            return \Illuminate\Support\Facades\DB::connection('sakemaru')
                                ->table('locations')
                                ->where('warehouse_id', $warehouseId)
                                ->orderBy('code1')
                                ->orderBy('code2')
                                ->orderBy('code3')
                                ->get()
                                ->mapWithKeys(fn($loc) => [
                                    $loc->id => "{$loc->code1}{$loc->code2}{$loc->code3} - {$loc->name}"
                                ])
                                ->toArray();
                        })
                        ->multiple()
                        ->required()
                        ->searchable(),

                    TextInput::make('item_count')
                        ->label('商品数')
                        ->helperText('在庫を生成する商品の数')
                        ->numeric()
                        ->default(30)
                        ->required()
                        ->minValue(5)
                        ->maxValue(100),

                    TextInput::make('stocks_per_item')
                        ->label('商品あたりの在庫レコード数')
                        ->helperText('各商品について複数ロケーションに在庫を分散')
                        ->numeric()
                        ->default(2)
                        ->required()
                        ->minValue(1)
                        ->maxValue(10),
                ])
                ->action(function (array $data): void {
                    try {
                        $params = [
                            '--warehouse-id' => $data['warehouse_id'],
                            '--item-count' => $data['item_count'],
                            '--stocks-per-item' => $data['stocks_per_item'],
                            '--locations' => $data['locations'],
                        ];

                        $exitCode = Artisan::call('testdata:stocks', $params);
                        $output = Artisan::output();

                        if ($exitCode === 0) {
                            $totalStocks = $data['item_count'] * $data['stocks_per_item'];
                            Notification::make()
                                ->title('在庫データを生成しました')
                                ->body("商品{$data['item_count']}種類、在庫レコード約{$totalStocks}件の生成が完了しました。")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($output)
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

            Action::make('generateEarnings')
                ->label('売上データ生成')
                ->icon('heroicon-o-currency-yen')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('売上テストデータを生成')
                ->modalDescription('BoozeCore APIを通じてテスト用の売上データを生成します。')
                ->modalWidth('2xl')
                ->form([
                    Select::make('warehouse_id')
                        ->label('倉庫')
                        ->options(\App\Models\Sakemaru\Warehouse::pluck('name', 'id'))
                        ->default(991)
                        ->required()
                        ->searchable()
                        ->reactive(),

                    TextInput::make('count')
                        ->label('生成件数')
                        ->numeric()
                        ->default(5)
                        ->required()
                        ->minValue(1)
                        ->maxValue(50),

                    Select::make('courses')
                        ->label('配送コース指定（任意）')
                        ->helperText('指定しない場合は全配送コースからランダム選択')
                        ->options(function (Get $get) {
                            $warehouseId = $get('warehouse_id');
                            if (!$warehouseId) {
                                return [];
                            }
                            return DeliveryCourse::where('warehouse_id', $warehouseId)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn($course) => [
                                    $course->code => "[{$course->code}] {$course->name}"
                                ])
                                ->toArray();
                        })
                        ->multiple()
                        ->searchable(),

                    Select::make('locations')
                        ->label('ロケーション指定（任意）')
                        ->helperText('指定しない場合は全ロケーションの在庫から商品選択')
                        ->options(function (Get $get) {
                            $warehouseId = $get('warehouse_id');
                            if (!$warehouseId) {
                                return [];
                            }
                            return \Illuminate\Support\Facades\DB::connection('sakemaru')
                                ->table('locations')
                                ->where('warehouse_id', $warehouseId)
                                ->orderBy('code1')
                                ->orderBy('code2')
                                ->orderBy('code3')
                                ->get()
                                ->mapWithKeys(fn($loc) => [
                                    $loc->id => "{$loc->code1}{$loc->code2}{$loc->code3} - {$loc->name}"
                                ])
                                ->toArray();
                        })
                        ->multiple()
                        ->searchable(),
                ])
                ->action(function (array $data): void {
                    try {
                        $params = [
                            '--count' => $data['count'],
                            '--warehouse-id' => $data['warehouse_id'],
                        ];

                        if (!empty($data['courses'])) {
                            $params['--courses'] = $data['courses'];
                        }

                        if (!empty($data['locations'])) {
                            $params['--locations'] = $data['locations'];
                        }

                        $exitCode = Artisan::call('testdata:earnings', $params);
                        $output = Artisan::output();

                        if ($exitCode === 0) {
                            $body = "{$data['count']}件のテストデータを生成しました。";
                            if (!empty($data['courses'])) {
                                $body .= " (配送コース: " . count($data['courses']) . "件指定)";
                            }
                            if (!empty($data['locations'])) {
                                $body .= " (ロケーション: " . count($data['locations']) . "件指定)";
                            }

                            Notification::make()
                                ->title('売上データを生成しました')
                                ->body($body)
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($output)
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

            Action::make('generateWaves')
                ->label('Wave生成')
                ->icon('heroicon-o-queue-list')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Waveを生成')
                ->modalDescription('Wave設定に基づいてピッキングタスクを生成します。')
                ->form([
                    DatePicker::make('date')
                        ->label('出荷日')
                        ->default(now())
                        ->required(),
                    Toggle::make('reset')
                        ->label('既存データをリセット')
                        ->helperText('既存のWave、ピッキングタスク、予約データを削除してから生成します。')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    try {
                        $params = [
                            '--date' => $data['date'],
                        ];

                        if ($data['reset']) {
                            $params['--reset'] = true;
                        }

                        $exitCode = Artisan::call('wms:generate-waves', $params);
                        $output = Artisan::output();

                        if ($exitCode === 0) {
                            Notification::make()
                                ->title('Waveを生成しました')
                                ->body('Wave生成が完了しました。')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($output)
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

            Action::make('generatePickerWave')
                ->label('ピッカー別Wave生成')
                ->icon('heroicon-o-user-group')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('ピッカー別Wave生成')
                ->modalDescription('特定のピッカーに複数の配送コースのピッキングタスクを生成します。')
                ->modalWidth('2xl')
                ->form([
                    Select::make('picker_id')
                        ->label('ピッカー')
                        ->options(WmsPicker::active()->get()->pluck('display_name', 'id'))
                        ->required()
                        ->searchable()
                        ->reactive()
                        ->afterStateUpdated(function (Set $set, ?int $state) {
                            if ($state) {
                                $picker = WmsPicker::find($state);
                                if ($picker && $picker->default_warehouse_id) {
                                    $set('warehouse_id', $picker->default_warehouse_id);
                                }
                            }
                        }),

                    Select::make('warehouse_id')
                        ->label('倉庫')
                        ->options(\App\Models\Sakemaru\Warehouse::pluck('name', 'id'))
                        ->default(991)
                        ->required()
                        ->searchable()
                        ->reactive(),

                    Repeater::make('courses')
                        ->label('配送コース別伝票枚数')
                        ->schema([
                            Select::make('course_code')
                                ->label('配送コース')
                                ->options(function (Get $get) {
                                    $warehouseId = $get('../../warehouse_id');
                                    if (!$warehouseId) {
                                        return [];
                                    }
                                    return DeliveryCourse::where('warehouse_id', $warehouseId)
                                        ->orderBy('code')
                                        ->get()
                                        ->mapWithKeys(fn($course) => [
                                            $course->code => "[{$course->code}] {$course->name}"
                                        ])
                                        ->toArray();
                                })
                                ->required()
                                ->searchable()
                                ->distinct()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems(),

                            TextInput::make('count')
                                ->label('伝票枚数')
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->maxValue(20)
                                ->default(3),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->maxItems(10)
                        ->addActionLabel('配送コースを追加')
                        ->collapsible()
                        ->itemLabel(function (array $state): ?string {
                            if (isset($state['course_code'])) {
                                $count = $state['count'] ?? 0;
                                return "コース {$state['course_code']}: {$count}件";
                            }
                            return null;
                        }),

                    DatePicker::make('date')
                        ->label('出荷日')
                        ->default(now())
                        ->required(),

                    Toggle::make('reset')
                        ->label('既存データをリセット')
                        ->helperText('既存のWave、ピッキングタスク、予約データを削除してから生成します。')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    try {
                        // Build Artisan command parameters
                        $params = [
                            'picker_id' => $data['picker_id'],
                            '--warehouse-id' => $data['warehouse_id'],
                            '--date' => $data['date'],
                            '--courses' => array_map(
                                fn($c) => "{$c['course_code']}:{$c['count']}",
                                $data['courses']
                            ),
                        ];

                        if ($data['reset']) {
                            $params['--reset'] = true;
                        }

                        // Execute command
                        $exitCode = Artisan::call('testdata:picker-wave', $params);

                        $output = Artisan::output();

                        if ($exitCode === 0) {
                            $totalEarnings = array_sum(array_column($data['courses'], 'count'));
                            Notification::make()
                                ->title('ピッカー別Waveを生成しました')
                                ->body("伝票{$totalEarnings}件、配送コース" . count($data['courses']) . "件のWaveを生成しました。")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($output)
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
        ];
    }
}
