<?php

namespace App\Console\Commands\AutoOrder;

use App\Services\AutoReplenishment\DemandEventCalendar;
use App\Services\AutoReplenishment\DemandEventHistoryReader;
use App\Services\AutoReplenishment\DemandEventLearningService;
use App\Services\AutoReplenishment\Replay\ReplayCalendar;
use App\Services\AutoReplenishment\Replay\ReplayDataReader;
use App\Services\AutoReplenishment\Replay\ReplayEngine;
use App\Services\AutoReplenishment\Replay\ReplayMetrics;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Past replay with the production calculators. Reads the database (SELECT only) and writes CSV/JSON files under storage/app. */
class ReplayReplenishmentCommand extends Command
{
    protected $signature = 'wms:replay-replenishment {client : 対象顧客ID}
        {--from=2025-01-06 : 再生開始日}
        {--to=2026-01-20 : 再生終了日}
        {--scenarios=fixed,base,eventA : 比較案（fixed=固定設定, base=曜日別・週次, eventA=イベント補正・週次, eventAs=eventA＋ばらつき加算）}
        {--calendar=simple : simple（日曜なし・固定リード）または current（現在の倉庫・発注先設定を過去に適用）}
        {--lead=2 : simple のリード日数}
        {--stops=12-31:01-03 : simple の入荷停止期間（月日、カンマ区切り。毎年適用）}
        {--extra-items=10 : 設定のある商品に加える売上上位の商品数}
        {--safety=5 : 安全在庫日数}
        {--unit=CASE : 発注単位の扱い（CASE/PIECE）}
        {--learn-through= : イベント倍率の学習終了日（既定：開始年の1月6日）}
        {--warmup=28 : 集計から除く開始日数}
        {--limit= : 系列数の上限（動作確認用）}
        {--out= : 出力フォルダ（既定 storage/app/replenishment-replay/<日時>）}';

    protected $description = '実際のおすすめ計算・発注計算で過去の需要を再生し、欠品・在庫・発注回数を比較案ごとに集計する（DBへの書き込みなし）';

    public function handle(ReplayDataReader $reader, ReplayEngine $engine, DemandEventHistoryReader $history, DemandEventCalendar $calendarEvents, DemandEventLearningService $learning): int
    {
        try {
            $client = (int) $this->argument('client');
            throw_unless($client === app(ReplenishmentGuard::class)->clientId(), \RuntimeException::class, '配備先の顧客IDと対象が一致しません。');
            $from = CarbonImmutable::parse($this->option('from'))->toDateString();
            $to = CarbonImmutable::parse($this->option('to'))->toDateString();
            throw_if($from >= $to, \RuntimeException::class, '再生期間を確認してください。');
            $scenarioNames = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('scenarios')))));
            $out = $this->option('out') ?: storage_path('app/replenishment-replay/'.now('Asia/Tokyo')->format('Ymd-His'));
            if (! is_dir($out)) {
                mkdir($out, 0775, true);
            }
            if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 2048) {
                ini_set('memory_limit', '2048M');
            }
            $list = $reader->seriesList($client, $from, $to, (int) $this->option('extra-items'));
            if ($this->option('limit')) {
                $list = array_slice($list, 0, (int) $this->option('limit'));
            }
            throw_unless($list, \RuntimeException::class, '対象の店舗×商品がありません。');
            $warehouseIds = array_values(array_unique(array_column($list, 'warehouse_id')));
            $itemIds = array_values(array_unique(array_column($list, 'item_id')));
            $items = $reader->items($client, $itemIds);
            $warehouses = $reader->warehouses($client, $warehouseIds);
            $this->info(count($list).'系列（倉庫 '.count($warehouseIds).'・商品 '.count($itemIds).'）、期間 '.$from.'〜'.$to.'、出力 '.$out);
            $demandStart = CarbonImmutable::parse($from)->subDays(ReplayEngine::HISTORY_DAYS + 10)->toDateString();
            $this->line('需要を読み込み中…');
            $demand = $reader->demand($client, $warehouseIds, $itemIds, $demandStart, $to);
            $years = range((int) substr($from, 0, 4) - 1, (int) substr($to, 0, 4));
            $events = [];
            foreach ($years as $year) {
                $events = array_merge($events, $calendarEvents->proposals($year, $history->holidays($year)));
            }
            $factors = fn (int $item) => ['item' => [], 'category' => []];
            if (count(array_intersect($scenarioNames, ['eventA', 'eventAs'])) > 0) {
                $through = $this->option('learn-through') ?: substr($from, 0, 4).'-01-06';
                $this->line("イベント倍率を学習中（{$through} まで、3年）…");
                $learned = $learning->learn($client, $through, 3, $itemIds, fn ($line) => $this->line('  '.$line));
                $this->line('  '.json_encode($learned['stats'], JSON_UNESCAPED_UNICODE));
                $factors = function (int $item) use ($learned, $items) {
                    $category = $items[$item]['item_category3_id'] ?? null;

                    return ['item' => $learned['items'][$item] ?? [], 'category' => $category ? ($learned['categories'][$category] ?? []) : []];
                };
            }
            $stops = [];
            foreach (array_filter(array_map('trim', explode(',', (string) $this->option('stops')))) as $range) {
                [$a, $b] = array_pad(explode(':', $range), 2, null);
                foreach ($years as $year) {
                    $startStop = "{$year}-{$a}";
                    $endStop = ($b && $b < $a ? $year + 1 : $year).'-'.($b ?: $a);
                    $stops[] = [$startStop, $endStop];
                }
            }
            $calendars = [];
            $conditions = ['from' => $from, 'to' => $to, 'calendar' => $this->option('calendar'), 'lead' => (int) $this->option('lead'), 'stops' => $stops,
                'safety_stock_days' => (int) $this->option('safety'), 'unit_mode' => $this->option('unit'), 'learn_through' => $through ?? null,
                'warmup_days' => (int) $this->option('warmup'), 'series' => count($list), 'events' => array_map(fn ($e) => $e['name'].' '.$e['start'].'〜'.$e['end'], $events)];
            file_put_contents($out.'/conditions.json', json_encode($conditions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $summary = [];
            foreach ($scenarioNames as $name) {
                $scenario = $this->scenario($name, $from, $to, $events, $factors);
                $seriesFile = fopen($out."/series_{$name}.csv", 'w');
                $daysFile = fopen($out."/days_{$name}.csv", 'w');
                $recalcFile = fopen($out."/recalcs_{$name}.csv", 'w');
                fputcsv($seriesFile, ['warehouse_id', 'warehouse', 'item_id', 'item_code', 'item', 'source', 'capacity_case', 'scored_days', 'demand', 'unmet', 'stockout_rate', 'unmet_days',
                    'mean_stock', 'mean_stock_days', 'max_stock', 'orders', 'order_pieces', 'minimum_lot_orders', 'event_orders', 'constrained_skips', 'hold_days', 'new_year_stock_days', 'overstock_order_days', 'segments']);
                fputcsv($daysFile, ['warehouse_id', 'item_id', 'date', 'segment', 'opening', 'received', 'ordered', 'arrival_date', 'demand', 'unmet', 'stock', 'pending', 'phase', 'reason', 'point', 'target', 'min', 'max', 'forecast']);
                fputcsv($recalcFile, ['warehouse_id', 'item_id', 'date', 'available', 'reason', 'point', 'target', 'min', 'max', 'unit', 'mean_daily', 'event_add_on', 'selection']);
                $results = [];
                $bar = $this->output->createProgressBar(count($list));
                $bar->setFormat(" {$name} %current%/%max% [%bar%] %elapsed%");
                foreach ($list as $entry) {
                    $item = $items[$entry['item_id']] ?? ['code' => '', 'name' => '', 'capacity_case' => 0];
                    $series = ['client_id' => $client, 'warehouse_id' => $entry['warehouse_id'], 'warehouse_code' => (string) ($warehouses[$entry['warehouse_id']]['code'] ?? ''),
                        'warehouse_name' => (string) ($warehouses[$entry['warehouse_id']]['name'] ?? ''), 'item_id' => $entry['item_id'], 'item_code' => (string) $item['code'],
                        'item_name' => (string) $item['name'], 'capacity_case' => (int) $item['capacity_case'], 'source' => $entry['source'],
                        'demand' => $demand[$entry['warehouse_id']][$entry['item_id']] ?? []];
                    $calendar = $this->calendar($reader, $calendars, $entry, (int) $this->option('lead'), $stops);
                    $result = $engine->run($series, $scenario, $calendar);
                    $m = $result['metrics'];
                    fputcsv($seriesFile, [$series['warehouse_id'], $series['warehouse_name'], $series['item_id'], $series['item_code'], $series['item_name'], $series['source'], $series['capacity_case'],
                        $m['scored_days'], $m['demand'], $m['unmet'], $m['stockout_rate'], $m['unmet_days'], $m['mean_stock'], $m['mean_stock_days'], $m['max_stock'], $m['orders'], $m['order_pieces'],
                        $m['minimum_lot_orders'], $m['event_orders'], $m['constrained_skips'], $m['hold_days'], $m['new_year_stock_days'], $m['overstock_order_days'], json_encode($m['segments'], JSON_UNESCAPED_UNICODE)]);
                    foreach ($result['days'] as $row) {
                        fputcsv($daysFile, [$series['warehouse_id'], $series['item_id'], $row['date'], $row['segment'], $row['opening'], $row['received'], $row['ordered'], $row['arrival_date'], $row['demand'],
                            $row['unmet'], $row['stock'], $row['pending'], $row['phase'], $row['reason'], $row['point'], $row['target'], $row['min'], $row['max'], $row['forecast']]);
                    }
                    foreach ($result['recalcs'] as $recalc) {
                        $v = $recalc['values'] ?? [];
                        fputcsv($recalcFile, [$series['warehouse_id'], $series['item_id'], $recalc['date'], (int) $recalc['available'], $recalc['reason'], $v['reorder_point_pieces'] ?? null, $v['target_stock_pieces'] ?? null,
                            $v['min_order_pieces'] ?? null, $v['max_order_pieces'] ?? null, $v['order_multiple_pieces'] ?? null, $recalc['mean_daily'] ?? null, $recalc['event_add_on'] ?? null, $recalc['selection'] ?? null]);
                    }
                    $results[] = ['metrics' => $m];
                    $bar->advance();
                }
                $bar->finish();
                $this->newLine();
                fclose($seriesFile);
                fclose($daysFile);
                fclose($recalcFile);
                $summary[$name] = (new ReplayMetrics)->aggregate($results);
                $this->report($name, $summary[$name]);
            }
            file_put_contents($out.'/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('出力: '.$out);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    private function scenario(string $name, string $from, string $to, array $events, \Closure $factors): array
    {
        $base = ['name' => $name, 'start' => $from, 'end' => $to, 'mode' => 'weekly', 'unit_mode' => $this->option('unit'), 'safety_stock_days' => (int) $this->option('safety'),
            'review_days' => 7, 'variability_buffer' => false, 'events' => [], 'factors' => null, 'segment_events' => $events, 'warmup_days' => (int) $this->option('warmup')];

        return match ($name) {
            'fixed' => array_replace($base, ['mode' => 'fixed']),
            'base' => $base,
            'eventA' => array_replace($base, ['events' => $events, 'factors' => $factors]),
            'eventAs' => array_replace($base, ['events' => $events, 'factors' => $factors, 'variability_buffer' => true]),
            default => throw new \RuntimeException("比較案 {$name} は未定義です（fixed, base, eventA, eventAs）。"),
        };
    }

    private function calendar(ReplayDataReader $reader, array &$cache, array $entry, int $lead, array $stops): ReplayCalendar
    {
        if ($this->option('calendar') !== 'current') {
            return $cache['simple'] ??= ReplayCalendar::simple($lead, $stops);
        }
        $key = $entry['warehouse_id'].':'.($entry['contractor_id'] ?? 0);
        if (! isset($cache[$key])) {
            if (! $entry['contractor_id']) {
                $this->warn("倉庫 {$entry['warehouse_id']} 商品 {$entry['item_id']}：発注先が無いため簡易カレンダーを使用");
                $cache[$key] = $cache['simple'] ??= ReplayCalendar::simple($lead, $stops);
            } else {
                $cache[$key] = ReplayCalendar::scheduled($reader->arrivalProvider((int) $entry['warehouse_id'], (int) $entry['contractor_id']));
            }
        }

        return $cache[$key];
    }

    private function report(string $name, array $a): void
    {
        $this->info("【{$name}】系列 {$a['series']}：欠品率 ".($a['stockout_rate'] === null ? '—' : round($a['stockout_rate'] * 100, 2).'%')
            .'（系列中央値 '.($a['median_series_stockout_rate'] === null ? '—' : round($a['median_series_stockout_rate'] * 100, 2).'%）')
            .' 平均在庫 '.number_format($a['mean_stock'], 1).'バラ（系列中央値 '.($a['median_series_stock_days'] ?? '—').'日分） 発注 '.number_format($a['orders']).'回（最小ロット '
            .($a['minimum_lot_share'] === null ? '—' : round($a['minimum_lot_share'] * 100).'%）').' イベント発注 '.$a['event_orders'].' 在庫21日超での発注 '.$a['overstock_order_days']
            .'日 制約見送り '.$a['constrained_skips'].'日 年始滞留 '.$a['new_year_overstock_series'].'系列');
        $rows = [];
        foreach ($a['segments'] as $segment => $s) {
            $rows[] = [$segment, number_format($s['demand']), number_format($s['unmet']), $s['stockout_rate'] === null ? '—' : round($s['stockout_rate'] * 100, 2).'%', number_format($s['mean_stock'], 1), $s['days']];
        }
        $this->table(['区分', '需要', '欠品', '欠品率', '平均在庫', '延べ日数'], $rows);
    }
}
