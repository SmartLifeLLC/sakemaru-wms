<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->boolean('is_synced')->default(false)
                ->after('is_finished')
                ->comment('ai-core同期済みフラグ');
            $table->timestamp('is_synced_at')->nullable()
                ->after('is_synced')
                ->comment('ai-core同期日時');

            $table->index('is_synced', 'idx_allocation_sync_status');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->dropIndex('idx_allocation_sync_status');
            $table->dropColumn(['is_synced', 'is_synced_at']);
        });
    }
};
