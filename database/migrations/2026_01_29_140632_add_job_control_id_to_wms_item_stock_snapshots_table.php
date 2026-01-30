<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wms_item_stock_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('job_control_id')->nullable()->after('id')->comment('実行ジョブID');
            $table->index('job_control_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wms_item_stock_snapshots', function (Blueprint $table) {
            $table->dropIndex(['job_control_id']);
            $table->dropColumn('job_control_id');
        });
    }
};
