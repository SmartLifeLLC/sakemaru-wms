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
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->boolean('is_finished')->default(false)->after('confirmed_user_id')->comment('完了フラグ');
            $table->timestamp('finished_at')->nullable()->after('is_finished')->comment('完了日時');
            $table->unsignedBigInteger('finished_user_id')->nullable()->after('finished_at')->comment('完了者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->dropColumn(['is_finished', 'finished_at', 'finished_user_id']);
        });
    }
};
