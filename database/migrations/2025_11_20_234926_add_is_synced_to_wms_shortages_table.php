<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->boolean('is_synced')->default(false)->after('is_confirmed')
                ->comment('基幹システムへの在庫同期完了フラグ');
            $table->timestamp('is_synced_at')->nullable()->after('is_synced')
                ->comment('基幹システムへの在庫同期完了日時');

            // Index for querying unsynced records
            $table->index(['is_synced', 'is_confirmed'], 'idx_shortage_sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->dropIndex('idx_shortage_sync_status');
            $table->dropColumn(['is_synced', 'is_synced_at']);
        });
    }
};
