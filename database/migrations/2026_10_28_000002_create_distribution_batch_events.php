<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_distribution_batch_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distribution_batch_id');
            $table->unsignedBigInteger('created_by');
            $table->string('operation', 100);
            $table->json('changes');
            $table->timestamp('created_at');
            $table->index(['distribution_batch_id', 'id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('監査履歴を保全するため、自動での削除は行いません。');
    }
};
