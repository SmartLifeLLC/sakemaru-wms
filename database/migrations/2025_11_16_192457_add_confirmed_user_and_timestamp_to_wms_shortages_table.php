<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add confirmed_user_id and confirmed_at to track who confirmed the shortage
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->unsignedBigInteger('confirmed_user_id')
                ->nullable()
                ->after('status')
                ->comment('処理確定実施者ID (users.id)');

            $table->timestamp('confirmed_at')
                ->nullable()
                ->after('confirmed_user_id')
                ->comment('処理確定日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->dropColumn(['confirmed_user_id', 'confirmed_at']);
        });
    }
};
