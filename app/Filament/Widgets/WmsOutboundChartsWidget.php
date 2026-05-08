<?php

namespace App\Filament\Widgets;

use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsDailyStat;
use App\Services\WmsStatsService;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class WmsOutboundChartsWidget extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = 'trend_7days';

    public function getHeading(): ?string
    {
        return match (true) {
            str_starts_with($this->filter, 'trend_') => '出荷トレンド',
            str_starts_with($this->filter, 'comparison_') => '倉庫別比較（当日）',
            default => '出荷統計',
        };
    }

    protected function getData(): array
    {
        // トレンドグラフ
        if (str_starts_with($this->filter, 'trend_')) {
            return $this->getTrendData();
        }

        // 倉庫比較グラフ
        if (str_starts_with($this->filter, 'comparison_')) {
            return $this->getComparisonData();
        }

        return ['datasets' => [], 'labels' => []];
    }

    protected function getTrendData(): array
    {
        $endDate = ClientSetting::systemDate() ?? now();

        // フィルターから日数を取得
        $days = match ($this->filter) {
            'trend_7days' => 7,
            'trend_14days' => 14,
            'trend_30days' => 30,
            default => 7,
        };

        $startDate = $endDate->copy()->subDays($days - 1);

        // 日付範囲を生成
        $dates = [];
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $dates[] = $currentDate->format('Y-m-d');
            $currentDate->addDay();
        }

        // 全倉庫の統計を日付ごとに集計
        $warehouses = Warehouse::where('is_active', true)
            ->where('is_virtual', false)
            ->pluck('id');

        $slipCounts = [];
        $itemCounts = [];
        $shipQtys = [];

        foreach ($dates as $date) {
            $dailyStats = WmsDailyStat::whereIn('warehouse_id', $warehouses)
                ->where('target_date', $date)
                ->get();

            $slipCounts[] = $dailyStats->sum('picking_slip_count');
            $itemCounts[] = $dailyStats->sum('picking_item_count');
            $shipQtys[] = $dailyStats->sum('total_ship_qty');
        }

        // ラベルを日付形式に変換
        $labels = array_map(function ($date) {
            return Carbon::parse($date)->format('n/j');
        }, $dates);

        return [
            'datasets' => [
                [
                    'label' => 'ピッキング伝票数',
                    'data' => $slipCounts,
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'ピッキング商品数',
                    'data' => $itemCounts,
                    'borderColor' => 'rgb(34, 197, 94)',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.1)',
                    'tension' => 0.3,
                ],
                [
                    'label' => '合計出荷数量',
                    'data' => $shipQtys,
                    'borderColor' => 'rgb(168, 85, 247)',
                    'backgroundColor' => 'rgba(168, 85, 247, 0.1)',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getComparisonData(): array
    {
        $date = ClientSetting::systemDate() ?? now();
        $statsService = app(WmsStatsService::class);

        // アクティブな倉庫を取得
        $warehouses = Warehouse::where('is_active', true)
            ->where('is_virtual', false)
            ->orderBy('name')
            ->get();

        $labels = [];
        $data = [];

        // フィルターからメトリクスを取得
        $metric = match ($this->filter) {
            'comparison_slip_count' => 'slip_count',
            'comparison_item_count' => 'item_count',
            'comparison_ship_qty' => 'ship_qty',
            'comparison_amount_in' => 'amount_ex',
            'comparison_shortage_count' => 'shortage_count',
            default => 'slip_count',
        };

        foreach ($warehouses as $warehouse) {
            try {
                $stat = $statsService->getOrUpdateDailyStats($date, $warehouse->id);

                $labels[] = $warehouse->name;

                $data[] = match ($metric) {
                    'slip_count' => $stat->picking_slip_count,
                    'item_count' => $stat->picking_item_count,
                    'ship_qty' => $stat->total_ship_qty,
                    'amount_ex' => $stat->total_amount_ex,
                    'shortage_count' => $stat->stockout_total_count,
                    default => $stat->picking_slip_count,
                };
            } catch (\Exception $e) {
                \Log::warning("Failed to get stats for warehouse {$warehouse->id}: ".$e->getMessage());
            }
        }

        // メトリクスに応じた色とラベルを設定
        [$label, $color, $bgColor] = match ($metric) {
            'slip_count' => ['ピッキング伝票数', 'rgb(59, 130, 246)', 'rgba(59, 130, 246, 0.5)'],
            'item_count' => ['ピッキング商品数', 'rgb(34, 197, 94)', 'rgba(34, 197, 94, 0.5)'],
            'ship_qty' => ['合計出荷数量', 'rgb(168, 85, 247)', 'rgba(168, 85, 247, 0.5)'],
            'amount_ex' => ['税抜合計金額（円）', 'rgb(251, 146, 60)', 'rgba(251, 146, 60, 0.5)'],
            'shortage_count' => ['欠品件数', 'rgb(239, 68, 68)', 'rgba(239, 68, 68, 0.5)'],
            default => ['ピッキング伝票数', 'rgb(59, 130, 246)', 'rgba(59, 130, 246, 0.5)'],
        };

        return [
            'datasets' => [
                [
                    'label' => $label,
                    'data' => $data,
                    'borderColor' => $color,
                    'backgroundColor' => $bgColor,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        // トレンドグラフは折れ線、比較グラフは棒グラフ
        return str_starts_with($this->filter, 'trend_') ? 'line' : 'bar';
    }

    protected function getFilters(): ?array
    {
        return [
            // トレンドグラフ
            'trend_7days' => '📈 トレンド: 過去7日間',
            'trend_14days' => '📈 トレンド: 過去14日間',
            'trend_30days' => '📈 トレンド: 過去30日間',

            // 倉庫比較グラフ
            'comparison_slip_count' => '📊 倉庫比較: ピッキング伝票数',
            'comparison_item_count' => '📊 倉庫比較: ピッキング商品数',
            'comparison_ship_qty' => '📊 倉庫比較: 合計出荷数量',
            'comparison_amount_in' => '📊 倉庫比較: 税抜合計金額',
            'comparison_shortage_count' => '📊 倉庫比較: 欠品件数',
        ];
    }

    protected function getOptions(): array
    {
        $baseOptions = [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];

        // トレンドグラフの場合は追加オプション
        if (str_starts_with($this->filter, 'trend_')) {
            $baseOptions['interaction'] = [
                'intersect' => false,
                'mode' => 'index',
            ];
        }

        return $baseOptions;
    }
}
