<?php

namespace App\Console\Commands;

use App\Services\AutoOrder\ContractorOrderConditionImport;
use App\Support\ExternalJsonReport;
use Illuminate\Console\Command;

class ImportContractorOrderConditions extends Command
{
    protected $signature = 'wms:import-contractor-order-conditions {file} {--client-id=} {--sheet=原本} {--overwrite} {--apply} {--report= : Git管理外の照合結果・変更前バックアップJSON}';

    protected $description = '問屋のExcelから発注条件を照合する（通常は確認のみ、--applyで取込）';

    public function handle(ContractorOrderConditionImport $import): int
    {
        try {
            $clientId = (int) ($this->option('client-id') ?: config('app.client_id'));
            if ($clientId < 1) {
                throw new \RuntimeException('対象のクライアントIDを指定してください。');
            }
            $plan = $import->plan($import->read($this->argument('file'), $this->option('sheet')), $clientId, (bool) $this->option('overwrite'));
            $this->table(['結果', '件数'], collect($plan['counts'])->map(fn ($count, $status) => [$status, $count])->values()->all());
            if ($path = $this->option('report')) {
                ExternalJsonReport::write($path, $plan);
            }
            if ($this->option('apply')) {
                if (! $path) {
                    throw new \RuntimeException('取込時は--reportで変更前の照合結果の保存先を指定してください。');
                }
                $this->info($import->apply($plan).'件の発注条件を取り込みました。');
            } else {
                $this->info('照合のみ実行しました。');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
