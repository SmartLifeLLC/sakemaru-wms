<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('sakemaru')->hasColumn('wms_waves', 'wave_group_id')) {
            return;
        }

        Schema::connection('sakemaru')->table('wms_waves', function (Blueprint $table) {
            $table->unsignedBigInteger('wave_group_id')->nullable()->after('id');
            $table->index('wave_group_id');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_waves', 'wave_group_id')) {
            return;
        }

        Schema::connection('sakemaru')->table('wms_waves', function (Blueprint $table) {
            $table->dropIndex(['wave_group_id']);
            $table->dropColumn('wave_group_id');
        });
    }
};
