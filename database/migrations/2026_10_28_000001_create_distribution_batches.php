<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_distribution_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('name', 100);
            $table->date('shipping_date')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('registration_key', 120)->unique();
            $table->boolean('is_legacy')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_id', 'warehouse_id', 'shipping_date'], 'distribution_batch_scope');
        });
        Schema::connection('sakemaru')->table('wms_distribution_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('distribution_batch_id')->nullable();
            $table->index(['distribution_batch_id', 'sort_order', 'id'], 'distribution_batch_rows_order');
            $table->index(['distribution_batch_id', 'item_id'], 'distribution_batch_rows_item');
        });
        Schema::connection('sakemaru')->create('wms_distribution_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distribution_batch_id');
            $table->uuid('execution_id')->unique();
            $table->unsignedBigInteger('created_by');
            $table->string('file_name');
            $table->string('file_hash', 64);
            $table->string('sheet_name');
            $table->json('settings');
            $table->json('summary');
            $table->json('changes');
            $table->timestamps();
            $table->index(['distribution_batch_id', 'id']);
        });
    }

    public function down(): void
    {
        // Batch boundaries must survive a code rollback once users have entered data.
        throw new RuntimeException('分配データを保全するため、この変更は自動で取り消せません。互換版で切り戻してください。');
    }
};
