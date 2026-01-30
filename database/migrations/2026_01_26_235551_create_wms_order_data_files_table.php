<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_order_data_files', function (Blueprint $table) {
            $table->id();
            $table->string('batch_code', 20)->index()->comment('バッチコード');
            $table->unsignedBigInteger('warehouse_id')->index()->comment('倉庫ID');
            $table->unsignedBigInteger('contractor_id')->index()->comment('発注先ID');
            $table->date('order_date')->comment('発注日');
            $table->date('expected_arrival_date')->nullable()->comment('入荷予定日');
            $table->string('file_path')->comment('CSVファイルパス');
            $table->unsignedInteger('file_size')->default(0)->comment('ファイルサイズ');
            $table->unsignedInteger('order_count')->default(0)->comment('発注件数');
            $table->unsignedInteger('total_quantity')->default(0)->comment('合計数量');
            $table->enum('status', ['GENERATED', 'DOWNLOADED'])->default('GENERATED')->comment('ステータス');
            $table->timestamp('downloaded_at')->nullable()->comment('ダウンロード日時');
            $table->unsignedBigInteger('downloaded_by')->nullable()->comment('ダウンロードユーザーID');
            $table->timestamps();

            $table->unique(['batch_code', 'warehouse_id', 'contractor_id'], 'uq_batch_warehouse_contractor');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_order_data_files');
    }
};
