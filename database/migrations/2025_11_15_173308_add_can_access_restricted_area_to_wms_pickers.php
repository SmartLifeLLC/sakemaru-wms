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
        Schema::connection('sakemaru')->table('wms_pickers', function (Blueprint $table) {
            $table->boolean('can_access_restricted_area')
                ->default(false)
                ->after('default_warehouse_id')
                ->comment('制限エリアアクセス権限 (0: 不可, 1: 可能)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_pickers', function (Blueprint $table) {
            $table->dropColumn('can_access_restricted_area');
        });
    }
};
