<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasColumn('wms_order_data_files', 'candidate_ids')) {
            return;
        }

        Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table) {
            $table->json('candidate_ids')
                ->nullable()
                ->after('contractor_id')
                ->comment('この発注データファイルに含めたwms_order_candidates.id一覧');
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('wms_order_data_files', 'candidate_ids')) {
            return;
        }

        Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table) {
            $table->dropColumn('candidate_ids');
        });
    }
};
