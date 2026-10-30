<?php

namespace App\Console\Commands\AutoOrder;

use App\Services\AutoReplenishment\DemandEventCalendar;
use App\Services\AutoReplenishment\ItemDemandEventService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetItemDemandEventsCommand extends Command
{
    protected $signature = 'wms:set-item-demand-events {client : 対象顧客ID}
        {--item=* : 商品ID（複数可）。--events と組み合わせて明示指定}
        {--events= : 適用するイベント種別をカンマ区切り（YEAR_END,OBON,GW）。空文字で全て解除}
        {--csv= : item_id,events 形式のCSV（events は "YEAR_END|OBON" のように | 区切り）}
        {--from-learned : 学習済み倍率からの候補（自社実績の本体平均 1.3倍以上・2年以上）を一括適用}
        {--apply : 変更を保存（未指定は表示のみ）}';

    protected $description = '商品ごとに適用する標準イベント（年末年始・お盆・GW）を登録する。適用したイベントだけが、その商品のおすすめ設定・倍率学習の基準・過去再生に使われる';

    public function handle(ItemDemandEventService $service): int
    {
        try {
            $client = (int) $this->argument('client');
            throw_unless($client === app(ReplenishmentGuard::class)->clientId(), \RuntimeException::class, '配備先の顧客IDと対象が一致しません。');
            $current = $service->matrix($client);
            $applied = $this->requested($service, $client, $current);
            throw_if($applied === [], \RuntimeException::class, '対象がありません。--item と --events、--csv、--from-learned のいずれかを指定してください。');
            $names = DB::connection('sakemaru')->table('items')->where('client_id', $client)->whereIn('id', array_keys($applied))->pluck('name', 'id');
            $rows = [];
            foreach ($applied as $itemId => $types) {
                $before = array_keys(array_filter($current[$itemId] ?? []));
                $rows[] = [$itemId, $names[$itemId] ?? '', implode(',', $before) ?: '—', implode(',', $types) ?: '—', $before == $types ? '' : '変更'];
            }
            $this->table(['商品ID', '商品名', '現在', '適用後', ''], $rows);
            if (! $this->option('apply')) {
                $this->warn('--apply を指定すると保存します。');

                return self::SUCCESS;
            }
            $counts = $service->apply($client, $applied, (int) config('replenishment.system_actor_id', 0), $this->option('from-learned') ? '学習済み倍率からの候補を一括適用' : null);
            $this->info("適用 {$counts['enabled']}・解除 {$counts['disabled']}・変更なし {$counts['unchanged']}。おすすめ設定への反映は週次の再計算または画面の再計算で行われます。");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /** item_id => [types] from the options; unknown products (no policy) are rejected. */
    private function requested(ItemDemandEventService $service, int $client, array $current): array
    {
        $applied = [];
        $parse = function (string $value): array {
            $types = array_values(array_filter(array_map('trim', preg_split('/[,|]/', $value))));
            foreach ($types as $type) {
                throw_unless(in_array($type, DemandEventCalendar::STANDARD_TYPES, true), \RuntimeException::class, "イベント種別を確認してください（{$type}）。YEAR_END / OBON / GW のみ。");
            }

            return $types;
        };
        if ($this->option('events') !== null || $this->option('item')) {
            throw_if($this->option('events') === null || ! $this->option('item'), \RuntimeException::class, '--item と --events は組み合わせて指定してください。');
            foreach ($this->option('item') as $item) {
                $applied[(int) $item] = $parse((string) $this->option('events'));
            }
        }
        if ($this->option('csv')) {
            $handle = fopen((string) $this->option('csv'), 'r');
            throw_unless($handle, \RuntimeException::class, 'CSVを開けません。');
            while (($line = fgetcsv($handle)) !== false) {
                if (! isset($line[0]) || ! ctype_digit(trim((string) $line[0]))) {
                    continue; // header or blank
                }
                $applied[(int) $line[0]] = $parse((string) ($line[1] ?? ''));
            }
            fclose($handle);
        }
        if ($this->option('from-learned')) {
            foreach ($service->suggestions($client, array_keys($current)) as $itemId => $types) {
                $suggested = array_keys(array_filter($types, fn ($t) => $t['suggest']));
                if ($suggested) {
                    $applied[(int) $itemId] = array_values(array_unique(array_merge(array_keys(array_filter($current[$itemId] ?? [])), $suggested)));
                }
            }
        }
        foreach (array_keys($applied) as $itemId) {
            throw_unless(isset($current[$itemId]), \RuntimeException::class, "商品ID {$itemId} に自動発注設定がありません。");
        }
        ksort($applied);

        return $applied;
    }
}
