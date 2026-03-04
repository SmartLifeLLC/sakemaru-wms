<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_contractor_settings', function (Blueprint $table) {
            $table->boolean('is_receive_enabled')->default(false)->after('order_mail_content');
            $table->string('receive_format', 20)->default('JX')->after('is_receive_enabled'); // JX, CSV
            $table->string('receive_time', 5)->nullable()->after('receive_format'); // HH:MM
            $table->boolean('is_receive_sun')->default(false)->after('receive_time');
            $table->boolean('is_receive_mon')->default(false)->after('is_receive_sun');
            $table->boolean('is_receive_tue')->default(false)->after('is_receive_mon');
            $table->boolean('is_receive_wed')->default(false)->after('is_receive_tue');
            $table->boolean('is_receive_thu')->default(false)->after('is_receive_wed');
            $table->boolean('is_receive_fri')->default(false)->after('is_receive_thu');
            $table->boolean('is_receive_sat')->default(false)->after('is_receive_fri');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_contractor_settings', function (Blueprint $table) {
            $table->dropColumn([
                'is_receive_enabled',
                'receive_format',
                'receive_time',
                'is_receive_sun',
                'is_receive_mon',
                'is_receive_tue',
                'is_receive_wed',
                'is_receive_thu',
                'is_receive_fri',
                'is_receive_sat',
            ]);
        });
    }
};
