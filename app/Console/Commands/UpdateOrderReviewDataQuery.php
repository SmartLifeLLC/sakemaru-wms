<?php

namespace App\Console\Commands;

use App\Services\AutoOrder\OrderReviewSalesSql;
use App\Support\ExternalJsonReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateOrderReviewDataQuery extends Command
{
    protected $signature = 'wms:update-order-review-query {--client-id=} {--query-id=} {--output= : 変更前後を保存するGit管理外の新規JSONファイル} {--apply}';

    protected $description = '倉庫別発注検討データの週実績を本部画面と同じ集計に変更する（通常は確認のみ）';

    public function handle(): int
    {
        try {
            $db = DB::connection('sakemaru');
            $query = $db->table('data_queries')->where('client_id', (int) ($this->option('client-id') ?: config('app.client_id')))->where('id', (int) $this->option('query-id'))->first();
            if (! $query || ! str_ends_with($query->name, '倉庫別発注検討データ')) {
                throw new \RuntimeException('対象クライアントの倉庫別発注検討データをIDで指定してください。');
            }
            $after = OrderReviewSalesSql::updateDataQuery($query->query);
            $path = $this->option('output');
            if ($path) {
                ExternalJsonReport::write($path, ['before' => $query, 'after' => $after]);
            }
            if ($this->option('apply') && $after !== $query->query) {
                if (! $path) {
                    throw new \RuntimeException('--outputで変更前後の保存先を指定してください。');
                }
                $db->select('SELECT 1 FROM '.OrderReviewSalesSql::VIEW.' LIMIT 1');
                $count = $db->table('data_queries')->where('id', $query->id)->where('client_id', $query->client_id)
                    ->whereRaw('BINARY `query` = ?', [$query->query])->update(['query' => $after, 'updated_at' => now()]);
                if ($count !== 1) {
                    throw new \RuntimeException('照合後にクエリが変更されました。再照合してください。');
                }
                $this->info('実績集計を更新しました。');
            } else {
                $this->info($after === $query->query ? '同じ集計条件が設定済みです。' : '照合のみ実行しました。');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
