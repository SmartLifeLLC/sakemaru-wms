<?php

use App\Models\Sakemaru\User as SakemaruUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        $connection = DB::connection($this->connection);

        $connection->table('wms_order_data_files')
            ->whereNull('created_by_name')
            ->whereNotNull('candidate_ids')
            ->chunkById(100, function ($files) use ($connection): void {
                foreach ($files as $file) {
                    $candidateIds = json_decode((string) $file->candidate_ids, true);
                    if (! is_array($candidateIds) || $candidateIds === []) {
                        continue;
                    }

                    $createdByName = $connection->table('wms_order_candidates as c')
                        ->join('wms_auto_order_job_controls as j', 'j.batch_code', '=', 'c.batch_code')
                        ->join('users as u', 'u.id', '=', 'j.created_by')
                        ->whereIn('c.id', array_map('intval', $candidateIds))
                        ->whereNotNull('j.created_by')
                        ->orderByDesc('j.id')
                        ->value('u.name');

                    if (! filled($createdByName)) {
                        continue;
                    }

                    $connection->table('wms_order_data_files')
                        ->where('id', $file->id)
                        ->whereNull('created_by_name')
                        ->update(['created_by_name' => $createdByName]);
                }
            });

        $automatorId = SakemaruUser::resolveAutomatorId();
        $automatorName = $automatorId > 0
            ? SakemaruUser::withoutGlobalScopes()->whereKey($automatorId)->value('name')
            : null;

        if (filled($automatorName)) {
            $connection->table('wms_order_data_files')
                ->whereNull('created_by_name')
                ->where('batch_code', 'like', 'R%')
                ->update(['created_by_name' => $automatorName]);
        }
    }

    public function down(): void
    {
        // Historical creator names are intentionally kept.
    }
};
