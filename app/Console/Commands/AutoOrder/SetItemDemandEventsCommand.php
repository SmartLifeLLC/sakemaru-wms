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
        {--item=* : 商品CD（既定。--key=id で items.id）。複数可。--events と組み合わせて明示指定}
        {--events= : 適用するイベント種別をカンマ区切り（YEAR_END,OBON,GW）。空文字で全て解除}
        {--csv= : 1列目=商品CD（--key=id なら items.id）、2列目=events のCSV（events は "YEAR_END|OBON" のように | 区切り。見出し行可）}
        {--key=code : --item と CSV の商品の指定方法（code=商品CD, id=items.id）}
        {--from-learned : 学習済み倍率からの候補（自社実績の本体平均 1.3倍以上・2年以上）を表示。登録には --apply --accept-candidates}
        {--accept-candidates : --from-learned の候補を検証済み一覧の代わりにそのまま登録する（初回登録は検証済みの明示一覧を推奨）}
        {--apply : 変更を保存（未指定は表示のみ）}';

    protected $description = '商品ごとに適用する標準イベント（年末年始・お盆・GW）を登録する。適用したイベントだけが、その商品のおすすめ設定・倍率学習の基準・過去再生に使われる';

    public function handle(ItemDemandEventService $service): int
    {
        try {
            $client = (int) $this->argument('client');
            throw_unless($client === app(ReplenishmentGuard::class)->clientId(), \RuntimeException::class, '配備先の顧客IDと対象が一致しません。');
            throw_unless(in_array($this->option('key'), ['code', 'id'], true), \RuntimeException::class, '--key は code または id で指定してください。');
            $current = $service->matrix($client);
            $applied = $this->requested($service, $client, $current);
            throw_if($applied === [], \RuntimeException::class, '対象がありません。--item と --events、--csv、--from-learned のいずれかを指定してください。');
            $items = DB::connection('sakemaru')->table('items')->where('client_id', $client)->whereIn('id', array_keys($applied))->get(['id', 'code', 'name'])->keyBy('id');
            $rows = [];
            foreach ($applied as $itemId => $types) {
                $before = array_keys(array_filter($current[$itemId] ?? []));
                $rows[] = [$itemId, $items[$itemId]->code ?? '', $items[$itemId]->name ?? '', implode(',', $before) ?: '—', implode(',', $types) ?: '—', $before == $types ? '' : '変更'];
            }
            $this->table(['items.id', '商品CD', '商品名', '現在', '適用後', ''], $rows);
            if (! $this->option('apply')) {
                $this->warn('--apply を指定すると保存します。'.($this->option('from-learned') ? '--from-learned の候補を登録するには --accept-candidates も必要です（候補は期間平均1.3倍以上・2年以上の目安で、店舗別の数量・検証に基づく確定表ではありません）。' : ''));

                return self::SUCCESS;
            }
            throw_if($this->option('from-learned') && ! $this->option('accept-candidates'), \RuntimeException::class,
                '--from-learned の候補は目安です。検証済みの一覧を --csv で登録するか、候補をそのまま登録する場合は --accept-candidates を付けてください。');
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
        $byKey = []; // item key as given (code or id) => types
        if ($this->option('events') !== null || $this->option('item')) {
            throw_if($this->option('events') === null || ! $this->option('item'), \RuntimeException::class, '--item と --events は組み合わせて指定してください。');
            foreach ($this->option('item') as $item) {
                $byKey[trim((string) $item)] = $parse((string) $this->option('events'));
            }
        }
        if ($this->option('csv')) {
            $handle = fopen((string) $this->option('csv'), 'r');
            throw_unless($handle, \RuntimeException::class, 'CSVを開けません。');
            while (($line = fgetcsv($handle)) !== false) {
                $first = trim((string) ($line[0] ?? ''));
                if ($first === '' || str_starts_with($first, '#') || ! ctype_digit($first)) {
                    continue; // header, comment or blank
                }
                $byKey[$first] = $parse((string) ($line[1] ?? ''));
            }
            fclose($handle);
        }
        foreach ($this->resolveItems($client, array_keys($byKey)) as $key => $itemId) {
            $applied[$itemId] = $byKey[$key];
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
            throw_unless(isset($current[$itemId]), \RuntimeException::class, "商品（items.id {$itemId}）に自動発注設定がありません。");
        }
        ksort($applied);

        return $applied;
    }

    /** Given keys (商品CD by default, items.id with --key=id) → items.id; unknown or ambiguous keys fail without writing. */
    private function resolveItems(int $client, array $keys): array
    {
        if (! $keys) {
            return [];
        }
        if ($this->option('key') === 'id') {
            $found = DB::connection('sakemaru')->table('items')->where('client_id', $client)->whereIn('id', $keys)->pluck('id')->map(fn ($v) => (string) $v)->all();
            $missing = array_diff($keys, $found);
            throw_if($missing, \RuntimeException::class, 'items.id が見つかりません：'.implode(', ', $missing));

            return array_combine($keys, array_map('intval', $keys));
        }
        $rows = DB::connection('sakemaru')->table('items')->where('client_id', $client)->whereIn('code', $keys)->get(['id', 'code']);
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->code][] = (int) $row->id;
        }
        $resolved = [];
        foreach ($keys as $key) {
            $ids = $map[$key] ?? [];
            throw_if(count($ids) === 0, \RuntimeException::class, "商品CD {$key} が見つかりません（items.id で指定する場合は --key=id）。");
            throw_if(count($ids) > 1, \RuntimeException::class, "商品CD {$key} が複数の商品に一致します（items.id ".implode('/', $ids).'）。--key=id で指定してください。');
            $resolved[$key] = $ids[0];
        }

        return $resolved;
    }
}
