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
        Schema::connection('sakemaru')->table('wms_jx_transmission_logs', function (Blueprint $table) {
            $table->string('environment', 20)->default('production')->after('direction')->comment('環境区分: production/test');
            $table->index('environment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_jx_transmission_logs', function (Blueprint $table) {
            $table->dropIndex(['environment']);
            $table->dropColumn('environment');
        });
    }
};
