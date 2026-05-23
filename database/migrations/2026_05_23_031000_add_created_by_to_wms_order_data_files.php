<?php

use App\Models\Sakemaru\User as SakemaruUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('wms_order_data_files', 'created_by')) {
            Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table): void {
                $table->unsignedBigInteger('created_by')
                    ->nullable()
                    ->after('batch_code')
                    ->comment('作成者ID');
                $table->index('created_by', 'idx_wms_order_data_files_created_by');
            });
        }

        $connection = DB::connection($this->connection);

        $connection->statement('
            UPDATE wms_order_data_files f
            JOIN (
                SELECT batch_code, MAX(id) AS job_control_id
                FROM wms_auto_order_job_controls
                WHERE created_by IS NOT NULL
                GROUP BY batch_code
            ) latest ON latest.batch_code = f.batch_code
            JOIN wms_auto_order_job_controls j ON j.id = latest.job_control_id
            SET f.created_by = j.created_by
            WHERE f.created_by IS NULL
        ');

        $connection->table('wms_order_data_files')
            ->whereNull('created_by')
            ->whereNotNull('candidate_ids')
            ->chunkById(100, function ($files) use ($connection): void {
                foreach ($files as $file) {
                    $candidateIds = json_decode((string) $file->candidate_ids, true);
                    if (! is_array($candidateIds) || $candidateIds === []) {
                        continue;
                    }

                    $createdBy = $connection->table('wms_order_candidates as c')
                        ->join('wms_auto_order_job_controls as j', 'j.batch_code', '=', 'c.batch_code')
                        ->whereIn('c.id', array_map('intval', $candidateIds))
                        ->whereNotNull('j.created_by')
                        ->orderByDesc('j.id')
                        ->value('j.created_by');

                    if ($createdBy === null) {
                        continue;
                    }

                    $connection->table('wms_order_data_files')
                        ->where('id', $file->id)
                        ->whereNull('created_by')
                        ->update(['created_by' => (int) $createdBy]);
                }
            });

        $automatorId = SakemaruUser::resolveAutomatorId();
        if ($automatorId > 0) {
            $connection->table('wms_order_data_files')
                ->whereNull('created_by')
                ->where('batch_code', 'like', 'R%')
                ->update(['created_by' => $automatorId]);
        }
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('wms_order_data_files', 'created_by')) {
            return;
        }

        Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table): void {
            $table->dropIndex('idx_wms_order_data_files_created_by');
            $table->dropColumn('created_by');
        });
    }
};
