<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Enums\PickerSkillLevel;
use App\Enums\TemperatureType;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsPicker;
use App\Models\WmsWarehouseAutoOrderSetting;
use App\Models\WmsWarehouseHolidaySetting;
use App\Services\AutoOrder\CalendarGenerationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

class TestDataGenerator extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected string $view = 'filament.pages.test-data-generator';

    #[Url]
    public string $activeTab = 'shipping';

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

    public function getTitle(): string|HtmlString
    {
        return new HtmlString(
            'Test Data Generator <span class="ml-2 inline-flex items-center rounded-md bg-warning-50 dark:bg-warning-400/10 px-2 py-1 text-xs font-medium text-warning-700 dark:text-warning-400 ring-1 ring-inset ring-warning-600/20 dark:ring-warning-400/20">テスト環境のみ</span>'
        );
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    /**
     * 出荷テスト用アクション名
     */
    public function getShippingActionNames(): array
    {
        return [
            'generateWaveSettings',
            'generatePickers',
            'generatePickerWave',
            'generateEarnings',
            'resetTestData',  // 出荷テストデータのリセット
        ];
    }

    /**
     * 倉庫テストデータ用アクション名
     */
    public function getWarehouseActionNames(): array
    {
        return [
            'generateWmsData',
            'updateItemTemperatureTypes',
            'generateAlcoholStocks',
            'generateStocks',
            'truncateAllData',  // WMSデータのTRUNCATE
        ];
    }

    /**
     * 発注テスト用アクション名
     */
    public function getAutoOrderActionNames(): array
    {
        return [
            'generateAutoOrderSettings',
            'generateNationalHolidays',
            'generateWarehouseCalendars',
            'generateItemSupplySettings',
            'resetAutoOrderTestData',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function resetTestDataAction(): Action
    {
        return Action::make('resetTestData')
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
                    ->helperText('real_stocksを削除')
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

                    if (! empty($data['warehouse_id'])) {
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
            });
    }

    public function truncateAllDataAction(): Action
    {
        return Action::make('truncateAllData')
            ->label('WMSデータTRUNCATE')
            ->icon('heroicon-o-fire')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('WMSテーブルをTRUNCATE')
            ->modalDescription('WMS関連テーブル（wms_*、real_stocks）をTRUNCATEします。基幹システムのテーブル（earnings, trades等）は削除されません。この操作は取り消せません。')
            ->modalSubmitActionLabel('TRUNCATEを実行')
            ->action(function (): void {
                try {
                    // WMS関連テーブルのみ削除（基幹システムのテーブルは削除しない）
                    $tables = [
                        'wms_shortages',
                        'wms_shortage_allocations',
                        'wms_waves',
                        'wms_picking_tasks',
                        'wms_picking_item_results',
                        'wms_pickers',
                        'wms_reservations',
                        'real_stocks',
                        // 以下は基幹システムのテーブルなので削除禁止
                        // 'earnings',
                        // 'trades',
                        // 'trade_prices',
                        // 'trade_items',
                        // 'trade_candidate_items',
                        // 'trade_balances',
                    ];

                    DB::connection('sakemaru')->statement('SET FOREIGN_KEY_CHECKS=0');

                    $truncatedCount = 0;
                    foreach ($tables as $table) {
                        try {
                            DB::connection('sakemaru')->table($table)->truncate();
                            $truncatedCount++;
                        } catch (\Exception $e) {
                            // テーブルが存在しない場合はスキップ
                        }
                    }

                    DB::connection('sakemaru')->statement('SET FOREIGN_KEY_CHECKS=1');

                    Notification::make()
                        ->title('TRUNCATEが完了しました')
                        ->body("{$truncatedCount}個のテーブルをTRUNCATEしました。")
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    DB::connection('sakemaru')->statement('SET FOREIGN_KEY_CHECKS=1');
                    Notification::make()
                        ->title('エラー')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    // ========================================
    // 出荷テスト用アクション
    // ========================================

    public function generateWaveSettingsAction(): Action
    {
        return Action::make('generateWaveSettings')
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
                    ->options(
                        \App\Models\Sakemaru\Warehouse::where('is_active', true)
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}] {$w->name}"])
                    )
                    ->searchable(),
                Toggle::make('reset')
                    ->label('既存設定をリセット')
                    ->helperText('既存のWave設定を削除してから生成します。')
                    ->default(false),
            ])
            ->action(function (array $data): void {
                try {
                    $params = [];

                    if (! empty($data['warehouse_id'])) {
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
            });
    }

    public function generatePickersAction(): Action
    {
        return Action::make('generatePickers')
            ->label('ピッカー生成')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('ピッカーを生成')
            ->modalDescription('指定した倉庫に5人のピッカーを生成します。各スキルレベル1人ずつ、異なる作業速度で生成されます。')
            ->form([
                Select::make('warehouse_id')
                    ->label('倉庫')
                    ->options(Warehouse::where('is_active', true)->pluck('name', 'id'))
                    ->required()
                    ->searchable(),
            ])
            ->action(function (array $data): void {
                try {
                    $warehouse = Warehouse::find($data['warehouse_id']);
                    if (! $warehouse) {
                        throw new \Exception('倉庫が見つかりません');
                    }

                    $warehouseCode = $warehouse->code;

                    $pickerConfigs = [
                        ['skill' => PickerSkillLevel::TRAINEE, 'speed' => 0.80],
                        ['skill' => PickerSkillLevel::JUNIOR, 'speed' => 0.80],
                        ['skill' => PickerSkillLevel::SENIOR, 'speed' => 1.00],
                        ['skill' => PickerSkillLevel::EXPERT, 'speed' => 1.00],
                        ['skill' => PickerSkillLevel::MASTER, 'speed' => 1.20],
                    ];

                    $existingMaxSeq = WmsPicker::where('code', 'like', "{$warehouseCode}-%")
                        ->get()
                        ->map(function ($picker) use ($warehouseCode) {
                            $code = $picker->code;
                            $seq = str_replace("{$warehouseCode}-", '', $code);

                            return is_numeric($seq) ? (int) $seq : 0;
                        })
                        ->max() ?? 0;

                    $createdCount = 0;
                    foreach ($pickerConfigs as $index => $config) {
                        $seq = $existingMaxSeq + $index + 1;
                        $code = "{$warehouseCode}-".str_pad($seq, 3, '0', STR_PAD_LEFT);
                        $name = $config['skill']->label().' '.number_format($config['speed'], 1).'x';

                        WmsPicker::create([
                            'code' => $code,
                            'name' => $name,
                            'password' => Hash::make('password'),
                            'default_warehouse_id' => $warehouse->id,
                            'skill_level' => $config['skill']->value,
                            'picking_speed_rate' => $config['speed'],
                            'is_active' => true,
                            'can_access_restricted_area' => false,
                            'is_available_for_picking' => false,
                            'current_warehouse_id' => null,
                        ]);
                        $createdCount++;
                    }

                    Notification::make()
                        ->title('ピッカーを生成しました')
                        ->body("{$warehouse->name}に{$createdCount}人のピッカーを生成しました。")
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('エラー')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public function generatePickerWaveAction(): Action
    {
        return Action::make('generatePickerWave')
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

                Select::make('locations')
                    ->label('ロケーション指定')
                    ->helperText('該当ロケーションの在庫商品のみで売上を生成します。在庫がない場合は自動生成されます。検索して選択してください。')
                    ->required()
                    ->multiple()
                    ->searchable()
                    ->getSearchResultsUsing(function (?string $search, Get $get) {
                        $warehouseId = $get('warehouse_id');
                        if (! $warehouseId) {
                            return [];
                        }

                        $query = \Illuminate\Support\Facades\DB::connection('sakemaru')
                            ->table('locations')
                            ->where('warehouse_id', $warehouseId);

                        if (! empty($search)) {
                            $query->where(function ($q) use ($search) {
                                $q->where('code1', 'like', "%{$search}%")
                                    ->orWhere('code2', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%")
                                    ->orWhere(\Illuminate\Support\Facades\DB::raw("CONCAT(code1, ' ', code2)"), 'like', "%{$search}%");
                            });
                        }

                        return $query->orderBy('code1')
                            ->orderBy('code2')
                            ->limit(500)
                            ->get()
                            ->mapWithKeys(fn ($loc) => [
                                $loc->id => trim("{$loc->code1} {$loc->code2}")." - {$loc->name}",
                            ])
                            ->toArray();
                    })
                    ->getOptionLabelsUsing(function (array $values): array {
                        if (empty($values)) {
                            return [];
                        }

                        return \Illuminate\Support\Facades\DB::connection('sakemaru')
                            ->table('locations')
                            ->whereIn('id', $values)
                            ->get()
                            ->mapWithKeys(fn ($loc) => [
                                $loc->id => trim("{$loc->code1} {$loc->code2}")." - {$loc->name}",
                            ])
                            ->toArray();
                    }),

                Repeater::make('courses')
                    ->label('配送コース別伝票枚数')
                    ->schema([
                        Select::make('course_code')
                            ->label('配送コース')
                            ->options(function (Get $get) {
                                $warehouseId = $get('../../warehouse_id');
                                if (! $warehouseId) {
                                    return [];
                                }

                                return DeliveryCourse::where('warehouse_id', $warehouseId)
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn ($course) => [
                                        $course->code => "[{$course->code}] {$course->name}",
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
                    $params = [
                        'picker_id' => $data['picker_id'],
                        '--warehouse-id' => $data['warehouse_id'],
                        '--date' => $data['date'],
                        '--courses' => array_map(
                            fn ($c) => "{$c['course_code']}:{$c['count']}",
                            $data['courses']
                        ),
                    ];

                    if (! empty($data['locations'])) {
                        $params['--locations'] = $data['locations'];
                    }

                    if ($data['reset']) {
                        $params['--reset'] = true;
                    }

                    $exitCode = Artisan::call('testdata:picker-wave', $params);
                    $output = Artisan::output();

                    if ($exitCode === 0) {
                        $totalEarnings = array_sum(array_column($data['courses'], 'count'));
                        Notification::make()
                            ->title('ピッカー別Waveを生成しました')
                            ->body("伝票{$totalEarnings}件、配送コース".count($data['courses']).'件のWaveを生成しました。')
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
            });
    }

    public function generateEarningsAction(): Action
    {
        return Action::make('generateEarnings')
            ->label('売上データ生成')
            ->icon('heroicon-o-currency-yen')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('売上テストデータを生成')
            ->modalDescription('BoozeCore APIを通じてテスト用の売上データを生成します。')
            ->modalWidth('2xl')
            ->schema([
                Select::make('warehouse_id')
                    ->label('倉庫')
                    ->options(\App\Models\Sakemaru\Warehouse::pluck('name', 'id'))
                    ->default(91)
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
                        if (! $warehouseId) {
                            return [];
                        }

                        return DeliveryCourse::where('warehouse_id', $warehouseId)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($course) => [
                                $course->code => "[{$course->code}] {$course->name}",
                            ])
                            ->toArray();
                    })
                    ->multiple()
                    ->searchable(),

                Select::make('locations')
                    ->label('ロケーション指定（任意）')
                    ->helperText('指定しない場合は全ロケーションの在庫から商品選択。検索して選択してください。')
                    ->multiple()
                    ->searchable()
                    ->getSearchResultsUsing(function (?string $search, Get $get) {
                        $warehouseId = $get('warehouse_id');
                        if (! $warehouseId) {
                            return [];
                        }

                        $query = \Illuminate\Support\Facades\DB::connection('sakemaru')
                            ->table('locations')
                            ->where('warehouse_id', $warehouseId);

                        if (! empty($search)) {
                            $query->where(function ($q) use ($search) {
                                $q->where('code1', 'like', "%{$search}%")
                                    ->orWhere('code2', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%")
                                    ->orWhere(\Illuminate\Support\Facades\DB::raw("CONCAT(code1, ' ', code2)"), 'like', "%{$search}%");
                            });
                        }

                        return $query->orderBy('code1')
                            ->orderBy('code2')
                            ->limit(500)
                            ->get()
                            ->mapWithKeys(fn ($loc) => [
                                $loc->id => trim("{$loc->code1} {$loc->code2}")." - {$loc->name}",
                            ])
                            ->toArray();
                    })
                    ->getOptionLabelsUsing(function (array $values): array {
                        if (empty($values)) {
                            return [];
                        }

                        return \Illuminate\Support\Facades\DB::connection('sakemaru')
                            ->table('locations')
                            ->whereIn('id', $values)
                            ->get()
                            ->mapWithKeys(fn ($loc) => [
                                $loc->id => trim("{$loc->code1} {$loc->code2}")." - {$loc->name}",
                            ])
                            ->toArray();
                    }),
            ])
            ->action(function (array $data): void {
                try {
                    $params = [
                        '--count' => $data['count'],
                        '--warehouse-id' => $data['warehouse_id'],
                    ];

                    if (! empty($data['courses'])) {
                        $params['--courses'] = $data['courses'];
                    }

                    if (! empty($data['locations'])) {
                        $params['--locations'] = $data['locations'];
                    }

                    $exitCode = Artisan::call('testdata:earnings', $params);
                    $output = Artisan::output();

                    if ($exitCode === 0) {
                        $body = "{$data['count']}件のテストデータを生成しました。";
                        if (! empty($data['courses'])) {
                            $body .= ' (配送コース: '.count($data['courses']).'件指定)';
                        }
                        if (! empty($data['locations'])) {
                            $body .= ' (ロケーション: '.count($data['locations']).'件指定)';
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
            });
    }

    // ========================================
    // 倉庫テストデータ用アクション
    // ========================================

    public function generateWmsDataAction(): Action
    {
        return Action::make('generateWmsData')
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
                            fn ($f) => "{$f['number']}|{$f['name']}",
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
                            ->body("フロア{$totalFloors}件、ロケーション約".($totalFloors * $totalLocationsPerFloor).'件の生成が完了しました。')
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
            });
    }

    public function updateItemTemperatureTypesAction(): Action
    {
        return Action::make('updateItemTemperatureTypes')
            ->label('商品温度帯設定')
            ->icon('heroicon-o-sun')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('商品の温度帯を自動設定')
            ->modalDescription('商品名から適切な温度帯（常温/定温/冷蔵/冷凍）を判定し、一括で設定します。')
            ->modalSubmitActionLabel('温度帯を設定')
            ->action(function (): void {
                try {
                    $items = DB::connection('sakemaru')
                        ->table('items')
                        ->where('is_active', true)
                        ->get(['id', 'name', 'temperature_type']);

                    $updatedCount = 0;
                    $results = [
                        'FROZEN' => 0,
                        'CHILLED' => 0,
                        'CONSTANT' => 0,
                        'NORMAL' => 0,
                    ];

                    foreach ($items as $item) {
                        $newType = self::detectTemperatureType($item->name);

                        if ($newType !== $item->temperature_type) {
                            DB::connection('sakemaru')
                                ->table('items')
                                ->where('id', $item->id)
                                ->update(['temperature_type' => $newType]);
                            $updatedCount++;
                        }
                        $results[$newType]++;
                    }

                    $summary = collect($results)
                        ->map(fn ($count, $type) => TemperatureType::from($type)->label().": {$count}件")
                        ->implode(', ');

                    Notification::make()
                        ->title('温度帯設定が完了しました')
                        ->body("更新: {$updatedCount}件 / 全{$items->count()}件\n({$summary})")
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('エラー')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public function generateAlcoholStocksAction(): Action
    {
        return Action::make('generateAlcoholStocks')
            ->label('酒類在庫生成')
            ->icon('heroicon-o-beaker')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('酒類在庫データを生成')
            ->modalDescription('ALCOHOL商品に対して、温度帯・制限エリアを考慮した在庫データを大量生成します。')
            ->modalWidth('xl')
            ->form([
                Select::make('warehouse_id')
                    ->label('倉庫')
                    ->options(Warehouse::where('is_active', true)->pluck('name', 'id'))
                    ->required()
                    ->searchable(),

                TextInput::make('items_limit')
                    ->label('対象商品数（上限）')
                    ->helperText('0で全商品対象。テスト時は少数推奨。')
                    ->numeric()
                    ->default(100)
                    ->minValue(0)
                    ->maxValue(10000),

                TextInput::make('rare_percentage')
                    ->label('希少品(RARE)の割合 (%)')
                    ->helperText('制限エリアに配置される希少品の割合')
                    ->numeric()
                    ->default(10)
                    ->minValue(0)
                    ->maxValue(100),
            ])
            ->action(function (array $data): void {
                try {
                    Notification::make()
                        ->title('在庫生成を開始します')
                        ->body('処理中...')
                        ->info()
                        ->send();

                    $warehouseId = $data['warehouse_id'];
                    $itemsLimit = (int) $data['items_limit'];
                    $rarePercentage = (int) $data['rare_percentage'];

                    $itemsQuery = DB::connection('sakemaru')
                        ->table('items')
                        ->where('is_active', true)
                        ->where('type', 'ALCOHOL');

                    if ($itemsLimit > 0) {
                        $itemsQuery->limit($itemsLimit);
                    }

                    $items = $itemsQuery->get(['id', 'name', 'temperature_type', 'capacity_case', 'client_id']);

                    if ($items->isEmpty()) {
                        throw new \Exception('ALCOHOL商品が見つかりません');
                    }

                    $locations = DB::connection('sakemaru')
                        ->table('locations')
                        ->where('warehouse_id', $warehouseId)
                        ->get(['id', 'floor_id', 'available_quantity_flags', 'temperature_type', 'is_restricted_area']);

                    if ($locations->isEmpty()) {
                        throw new \Exception('ロケーションが見つかりません');
                    }

                    $locationGroups = [];
                    foreach ($locations as $loc) {
                        $tempType = $loc->temperature_type ?? 'NORMAL';
                        $restricted = $loc->is_restricted_area ? 'restricted' : 'standard';
                        $key = "{$tempType}_{$restricted}";
                        if (! isset($locationGroups[$key])) {
                            $locationGroups[$key] = [];
                        }
                        $locationGroups[$key][] = $loc;
                    }

                    $stockRecords = [];
                    $now = now();
                    $stockAllocationId = 1;

                    foreach ($items as $item) {
                        $itemTempType = $item->temperature_type ?? 'NORMAL';
                        $isRare = (rand(1, 100) <= $rarePercentage);
                        $managementType = $isRare ? 'RARE' : 'STANDARD';
                        $restrictedKey = $isRare ? 'restricted' : 'standard';

                        $locationKey = "{$itemTempType}_{$restrictedKey}";
                        $candidateLocations = $locationGroups[$locationKey] ?? [];

                        if (empty($candidateLocations)) {
                            $fallbackKey = "{$itemTempType}_standard";
                            $candidateLocations = $locationGroups[$fallbackKey] ?? [];
                        }

                        if (empty($candidateLocations)) {
                            $candidateLocations = $locationGroups['NORMAL_standard'] ?? [];
                        }

                        if (empty($candidateLocations)) {
                            continue;
                        }

                        $location = $candidateLocations[array_rand($candidateLocations)];

                        $expirationPatterns = rand(1, 2);
                        $expirationDates = [];
                        for ($i = 0; $i < $expirationPatterns; $i++) {
                            $expirationDates[] = $now->copy()->addDays(rand(30, 365))->format('Y-m-d');
                        }

                        $flags = $location->available_quantity_flags ?? 3;
                        $capacityCase = $item->capacity_case ?? 12;

                        foreach ($expirationDates as $expDate) {
                            if ($flags & 1) {
                                $caseQty = rand(5, 50);
                                $pieceQty = $caseQty * $capacityCase;

                                // Note: 新スキーマでは real_stocks + real_stock_lots に分離
                                $stockRecords[] = [
                                    'stock' => [
                                        'client_id' => $item->client_id ?? 1,
                                        'warehouse_id' => $warehouseId,
                                        'stock_allocation_id' => $stockAllocationId++,
                                        'item_id' => $item->id,
                                        'current_quantity' => $pieceQty,
                                        'reserved_quantity' => 0,
                                        'wms_lock_version' => 0,
                                        'item_management_type' => $managementType,
                                        'order_rank' => 'A',
                                        'order_parameter' => null,
                                        'created_at' => $now,
                                        'updated_at' => $now,
                                        'lock_version' => 0,
                                    ],
                                    'lot' => [
                                        'floor_id' => $location->floor_id,
                                        'location_id' => $location->id,
                                        'expiration_date' => $expDate,
                                        'price' => 0,
                                        'content_amount' => 0,
                                        'container_amount' => 0,
                                        'initial_quantity' => $pieceQty,
                                        'current_quantity' => $pieceQty,
                                        'reserved_quantity' => 0,
                                        'status' => 'ACTIVE',
                                        'created_at' => $now,
                                        'updated_at' => $now,
                                    ],
                                    'management_type' => $managementType,
                                ];
                            }

                            if (($flags & 2) && ! ($flags & 1)) {
                                $pieceQty = rand(5, 50);

                                // Note: 新スキーマでは real_stocks + real_stock_lots に分離
                                $stockRecords[] = [
                                    'stock' => [
                                        'client_id' => $item->client_id ?? 1,
                                        'warehouse_id' => $warehouseId,
                                        'stock_allocation_id' => $stockAllocationId++,
                                        'item_id' => $item->id,
                                        'current_quantity' => $pieceQty,
                                        'reserved_quantity' => 0,
                                        'wms_lock_version' => 0,
                                        'item_management_type' => $managementType,
                                        'order_rank' => 'A',
                                        'order_parameter' => null,
                                        'created_at' => $now,
                                        'updated_at' => $now,
                                        'lock_version' => 0,
                                    ],
                                    'lot' => [
                                        'floor_id' => $location->floor_id,
                                        'location_id' => $location->id,
                                        'expiration_date' => $expDate,
                                        'price' => 0,
                                        'content_amount' => 0,
                                        'container_amount' => 0,
                                        'initial_quantity' => $pieceQty,
                                        'current_quantity' => $pieceQty,
                                        'reserved_quantity' => 0,
                                        'status' => 'ACTIVE',
                                        'created_at' => $now,
                                        'updated_at' => $now,
                                    ],
                                    'management_type' => $managementType,
                                ];
                            }
                        }
                    }

                    $insertedCount = 0;
                    if (! empty($stockRecords)) {
                        foreach ($stockRecords as $record) {
                            // Insert real_stock record
                            $realStockId = DB::connection('sakemaru')
                                ->table('real_stocks')
                                ->insertGetId($record['stock']);

                            // Insert lot record with real_stock_id
                            $lotData = $record['lot'];
                            $lotData['real_stock_id'] = $realStockId;
                            DB::connection('sakemaru')
                                ->table('real_stock_lots')
                                ->insert($lotData);

                            $insertedCount++;
                        }
                    }

                    $rareCount = collect($stockRecords)->where('management_type', 'RARE')->count();
                    $standardCount = $insertedCount - $rareCount;

                    Notification::make()
                        ->title('酒類在庫を生成しました')
                        ->body("対象商品: {$items->count()}件\n生成在庫: {$insertedCount}件\n(RARE: {$rareCount}件, STANDARD: {$standardCount}件)")
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('エラー')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public function generateStocksAction(): Action
    {
        return Action::make('generateStocks')
            ->label('在庫データ生成')
            ->icon('heroicon-o-archive-box')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('在庫データを生成')
            ->modalDescription('全商品に対して、指定された倉庫フロアの全ロケーションに均等に在庫データを生成します。')
            ->modalWidth('xl')
            ->form([
                Select::make('warehouse_id')
                    ->label('倉庫')
                    ->options(\App\Models\Sakemaru\Warehouse::pluck('name', 'id'))
                    ->default(991)
                    ->required()
                    ->searchable()
                    ->reactive(),

                Select::make('floor_id')
                    ->label('フロア')
                    ->helperText('選択したフロアの全ロケーションに在庫を均等に分布させます')
                    ->options(function (Get $get) {
                        $warehouseId = $get('warehouse_id');
                        if (! $warehouseId) {
                            return [];
                        }

                        return \Illuminate\Support\Facades\DB::connection('sakemaru')
                            ->table('floors')
                            ->where('warehouse_id', $warehouseId)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($floor) => [
                                $floor->id => $floor->name,
                            ])
                            ->toArray();
                    })
                    ->required()
                    ->searchable(),
            ])
            ->action(function (array $data): void {
                try {
                    $params = [
                        '--warehouse-id' => $data['warehouse_id'],
                        '--floor-id' => $data['floor_id'],
                    ];

                    $exitCode = Artisan::call('testdata:stocks', $params);
                    $output = Artisan::output();

                    if ($exitCode === 0) {
                        Notification::make()
                            ->title('在庫データを生成しました')
                            ->body('全商品に対して在庫データを均等に生成しました。')
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
            });
    }

    /**
     * 商品名から温度帯を判定する
     */
    private static function detectTemperatureType(string $name): string
    {
        // 冷凍キーワード（優先度高）
        $frozenKeywords = [
            '冷凍', 'フローズン', 'アイス', '氷', 'シャーベット',
            'ジェラート', '凍結', '冷凍食品',
        ];

        // 冷蔵キーワード
        $chilledKeywords = [
            '冷蔵', '生', '要冷蔵', 'フレッシュ', '生ビール',
            '牛乳', 'ミルク', 'ヨーグルト', 'チーズ', '乳製品',
            'バター', '生クリーム', 'プリン', 'ゼリー',
            '刺身', '寿司', '鮮魚', '生肉', '生鮮',
            'サラダ', '豆腐', '納豆', '漬物', 'キムチ',
            'ハム', 'ソーセージ', 'ベーコン', '生ハム',
            'ケーキ', '生菓子', 'クレープ',
            '果汁', 'ジュース100%', 'スムージー',
            '生酒', '生貯蔵', '要冷', 'チルド',
        ];

        // 定温キーワード（ワイン、一部の日本酒など）
        $constantKeywords = [
            'ワイン', 'シャンパン', 'スパークリング',
            '赤ワイン', '白ワイン', 'ロゼ',
            '純米', '大吟醸', '吟醸', '本醸造',
            'シェリー', 'ポート', 'マデイラ',
        ];

        $nameUpper = mb_strtoupper($name);

        // 冷凍チェック
        foreach ($frozenKeywords as $keyword) {
            if (mb_strpos($name, $keyword) !== false) {
                return TemperatureType::FROZEN->value;
            }
        }

        // 冷蔵チェック
        foreach ($chilledKeywords as $keyword) {
            if (mb_strpos($name, $keyword) !== false) {
                return TemperatureType::CHILLED->value;
            }
        }

        // 定温チェック
        foreach ($constantKeywords as $keyword) {
            if (mb_strpos($name, $keyword) !== false) {
                return TemperatureType::CONSTANT->value;
            }
        }

        // デフォルトは常温
        return TemperatureType::NORMAL->value;
    }

    // ========================================
    // 発注テスト用アクション
    // ========================================

    public function generateAutoOrderSettingsAction(): Action
    {
        return Action::make('generateAutoOrderSettings')
            ->label('倉庫発注設定')
            ->icon('heroicon-o-building-office')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('倉庫発注設定を生成')
            ->modalDescription('倉庫の自動発注設定と休日設定を一括生成します。')
            ->modalWidth('2xl')
            ->form([
                Select::make('warehouse_ids')
                    ->label('対象倉庫')
                    ->helperText('自動発注を有効にする倉庫を選択')
                    ->options(fn () => Warehouse::where('is_active', true)->pluck('name', 'id'))
                    ->multiple()
                    ->required()
                    ->searchable(),

                CheckboxList::make('regular_holiday_days')
                    ->label('定休日')
                    ->helperText('定休日とする曜日を選択')
                    ->options([
                        0 => '日曜日',
                        1 => '月曜日',
                        2 => '火曜日',
                        3 => '水曜日',
                        4 => '木曜日',
                        5 => '金曜日',
                        6 => '土曜日',
                    ])
                    ->default([0, 6])
                    ->columns(4),

                Toggle::make('is_national_holiday_closed')
                    ->label('祝日を休業とする')
                    ->default(true),

                Toggle::make('exclude_sunday_arrival')
                    ->label('日曜入荷を除外')
                    ->helperText('日曜日に入荷予定がある場合、翌営業日にずらす')
                    ->default(false),

                Toggle::make('exclude_holiday_arrival')
                    ->label('祝日入荷を除外')
                    ->helperText('祝日に入荷予定がある場合、翌営業日にずらす')
                    ->default(false),
            ])
            ->action(function (array $data): void {
                try {
                    $warehouseIds = $data['warehouse_ids'];
                    $regularHolidayDays = array_map('intval', $data['regular_holiday_days'] ?? []);
                    $isNationalHolidayClosed = $data['is_national_holiday_closed'] ?? true;
                    $excludeSundayArrival = $data['exclude_sunday_arrival'] ?? false;
                    $excludeHolidayArrival = $data['exclude_holiday_arrival'] ?? false;

                    $createdCount = 0;

                    DB::connection('sakemaru')->transaction(function () use (
                        $warehouseIds,
                        $regularHolidayDays,
                        $isNationalHolidayClosed,
                        $excludeSundayArrival,
                        $excludeHolidayArrival,
                        &$createdCount
                    ) {
                        foreach ($warehouseIds as $warehouseId) {
                            // 自動発注設定をUPSERT
                            WmsWarehouseAutoOrderSetting::updateOrCreate(
                                ['warehouse_id' => $warehouseId],
                                [
                                    'is_auto_order_enabled' => true,
                                    'exclude_sunday_arrival' => $excludeSundayArrival,
                                    'exclude_holiday_arrival' => $excludeHolidayArrival,
                                ]
                            );

                            // 休日設定をUPSERT
                            WmsWarehouseHolidaySetting::updateOrCreate(
                                ['warehouse_id' => $warehouseId],
                                [
                                    'regular_holiday_days' => $regularHolidayDays,
                                    'is_national_holiday_closed' => $isNationalHolidayClosed,
                                ]
                            );

                            $createdCount++;
                        }
                    });

                    Notification::make()
                        ->title('倉庫発注設定を生成しました')
                        ->body("{$createdCount}件の倉庫に発注設定を適用しました。")
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('エラー')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public function generateNationalHolidaysAction(): Action
    {
        return Action::make('generateNationalHolidays')
            ->label('祝日マスタ生成')
            ->icon('heroicon-o-calendar-days')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('祝日マスタを生成')
            ->modalDescription('日本の祝日データをマスタに登録します。既存データは維持されます。')
            ->form([
                TextInput::make('year')
                    ->label('対象年')
                    ->numeric()
                    ->default(now()->year)
                    ->required()
                    ->minValue(2020)
                    ->maxValue(2100),

                Toggle::make('include_next_year')
                    ->label('翌年も含める')
                    ->default(true),
            ])
            ->action(function (array $data): void {
                try {
                    $year = (int) $data['year'];
                    $includeNextYear = $data['include_next_year'] ?? true;

                    $service = new CalendarGenerationService;
                    $count = $service->generateNationalHolidays($year);

                    if ($includeNextYear) {
                        $count += $service->generateNationalHolidays($year + 1);
                    }

                    Notification::make()
                        ->title('祝日マスタを生成しました')
                        ->body("{$count}件の祝日を登録しました。")
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('エラー')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public function generateWarehouseCalendarsAction(): Action
    {
        return Action::make('generateWarehouseCalendars')
            ->label('カレンダー生成')
            ->icon('heroicon-o-calendar')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('営業日カレンダーを生成')
            ->modalDescription('倉庫ごとの営業日カレンダーを生成します。手動変更済みの日付は維持されます。')
            ->form([
                Select::make('warehouse_ids')
                    ->label('対象倉庫')
                    ->helperText('カレンダーを生成する倉庫を選択')
                    ->options(fn () => Warehouse::where('is_active', true)->pluck('name', 'id'))
                    ->multiple()
                    ->required()
                    ->searchable(),

                TextInput::make('months')
                    ->label('生成月数')
                    ->helperText('今日から何ヶ月分のカレンダーを生成するか')
                    ->numeric()
                    ->default(6)
                    ->required()
                    ->minValue(1)
                    ->maxValue(12),
            ])
            ->action(function (array $data): void {
                try {
                    $warehouseIds = $data['warehouse_ids'];
                    $months = (int) $data['months'];

                    $service = new CalendarGenerationService;
                    $totalCount = 0;

                    foreach ($warehouseIds as $warehouseId) {
                        // 休日設定がなければ作成
                        WmsWarehouseHolidaySetting::firstOrCreate(
                            ['warehouse_id' => $warehouseId],
                            [
                                'regular_holiday_days' => [0, 6],
                                'is_national_holiday_closed' => true,
                            ]
                        );

                        $count = $service->generateCalendar($warehouseId, $months);
                        $totalCount += $count;
                    }

                    Notification::make()
                        ->title('営業日カレンダーを生成しました')
                        ->body(count($warehouseIds)."倉庫、合計{$totalCount}日分のカレンダーを生成しました。")
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('エラー')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public function resetAutoOrderTestDataAction(): Action
    {
        return Action::make('resetAutoOrderTestData')
            ->label('発注データリセット')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('発注テストデータをリセット')
            ->modalDescription('発注関連のWMSテーブルをクリアします。この操作は取り消せません。')
            ->modalWidth('xl')
            ->form([
                Toggle::make('reset_candidates')
                    ->label('発注候補・移動候補をリセット')
                    ->helperText('wms_order_candidates, wms_stock_transfer_candidates, wms_order_calculation_logs')
                    ->default(true),

                Toggle::make('reset_calendars')
                    ->label('カレンダーをリセット')
                    ->helperText('wms_warehouse_calendars')
                    ->default(false),

                Toggle::make('reset_warehouse_settings')
                    ->label('倉庫発注設定をリセット')
                    ->helperText('wms_warehouse_auto_order_settings, wms_warehouse_holiday_settings')
                    ->default(false),

                Select::make('warehouse_ids')
                    ->label('対象倉庫')
                    ->helperText('指定しない場合は全倉庫が対象になります')
                    ->options(fn () => Warehouse::where('is_active', true)->pluck('name', 'id'))
                    ->multiple()
                    ->searchable(),
            ])
            ->action(function (array $data): void {
                try {
                    $warehouseIds = $data['warehouse_ids'] ?? [];
                    $resetCandidates = $data['reset_candidates'] ?? true;
                    $resetCalendars = $data['reset_calendars'] ?? false;
                    $resetWarehouseSettings = $data['reset_warehouse_settings'] ?? false;

                    $deletedCounts = [];

                    DB::connection('sakemaru')->transaction(function () use (
                        $warehouseIds,
                        $resetCandidates,
                        $resetCalendars,
                        $resetWarehouseSettings,
                        &$deletedCounts
                    ) {
                        // 発注候補・移動候補・計算ログをリセット
                        if ($resetCandidates) {
                            $query = DB::connection('sakemaru')->table('wms_order_candidates');
                            if (! empty($warehouseIds)) {
                                $query->whereIn('warehouse_id', $warehouseIds);
                            }
                            $deletedCounts['wms_order_candidates'] = $query->delete();

                            $query = DB::connection('sakemaru')->table('wms_stock_transfer_candidates');
                            if (! empty($warehouseIds)) {
                                $query->whereIn('satellite_warehouse_id', $warehouseIds);
                            }
                            $deletedCounts['wms_stock_transfer_candidates'] = $query->delete();

                            $query = DB::connection('sakemaru')->table('wms_order_calculation_logs');
                            if (! empty($warehouseIds)) {
                                $query->whereIn('warehouse_id', $warehouseIds);
                            }
                            $deletedCounts['wms_order_calculation_logs'] = $query->delete();
                        }

                        // カレンダーをリセット
                        if ($resetCalendars) {
                            $query = DB::connection('sakemaru')->table('wms_warehouse_calendars');
                            if (! empty($warehouseIds)) {
                                $query->whereIn('warehouse_id', $warehouseIds);
                            }
                            $deletedCounts['wms_warehouse_calendars'] = $query->delete();
                        }

                        // 倉庫発注設定をリセット
                        if ($resetWarehouseSettings) {
                            $query = DB::connection('sakemaru')->table('wms_warehouse_auto_order_settings');
                            if (! empty($warehouseIds)) {
                                $query->whereIn('warehouse_id', $warehouseIds);
                            }
                            $deletedCounts['wms_warehouse_auto_order_settings'] = $query->delete();

                            $query = DB::connection('sakemaru')->table('wms_warehouse_holiday_settings');
                            if (! empty($warehouseIds)) {
                                $query->whereIn('warehouse_id', $warehouseIds);
                            }
                            $deletedCounts['wms_warehouse_holiday_settings'] = $query->delete();
                        }
                    });

                    $summary = collect($deletedCounts)
                        ->filter(fn ($count) => $count > 0)
                        ->map(fn ($count, $table) => str_replace('wms_', '', $table).": {$count}件")
                        ->implode(', ');

                    if (empty($summary)) {
                        $summary = '削除対象のデータがありませんでした。';
                    }

                    Notification::make()
                        ->title('発注テストデータをリセットしました')
                        ->body($summary)
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('エラー')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
