<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneDistributionPreviews extends Command
{
    protected $signature = 'wms:prune-distribution-previews';

    protected $description = '作成から24時間を過ぎた分配Excelプレビューを削除します';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $count = 0;
        foreach ($disk->files('distribution/previews') as $path) {
            if (preg_match('~/[A-Za-z0-9]{64}\.json$~D', $path) && $disk->lastModified($path) < time() - 86400) {
                $disk->delete($path);
                $count++;
            }
        }
        $this->info("期限切れのプレビューを{$count}件削除しました。");

        return self::SUCCESS;
    }
}
