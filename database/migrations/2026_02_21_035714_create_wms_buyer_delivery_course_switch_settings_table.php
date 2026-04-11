<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_buyer_delivery_course_switch_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('buyer_id');
            $table->time('switch_time')->comment('切替時刻（15分単位）');
            $table->unsignedBigInteger('to_delivery_course_id')->comment('切替先配送コースID');
            $table->date('last_executed_date')->nullable()->comment('最終実行日');
            $table->timestamp('last_executed_at')->nullable()->comment('最終実行日時');
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['buyer_id', 'switch_time'], 'wms_buyer_dc_switch_buyer_time_unique');
            $table->index('switch_time', 'wms_buyer_dc_switch_time_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_buyer_delivery_course_switch_settings');
    }
};
