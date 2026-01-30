<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filament_filter_set_user', function (Blueprint $table) {
            $table->id();

            // 通常カラムとして定義（FKは後で貼る）
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('filter_set_id');

            $table->smallInteger('sort_order')->default(1);
        });

        // users（共有・prefixなし）への外部キー
        DB::statement('
        ALTER TABLE wms_filament_filter_set_user
        ADD CONSTRAINT fk_wms_ffsu_user_id
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
    ');

        // filament_filter_sets（prefixあり）への外部キー
        DB::statement('
        ALTER TABLE wms_filament_filter_set_user
        ADD CONSTRAINT fk_wms_ffsu_filter_set_id
        FOREIGN KEY (filter_set_id)
        REFERENCES wms_filament_filter_sets(id)
        ON DELETE CASCADE
    ');
    }

    public function down(): void
    {
        Schema::drop('filament_filter_set_user');
    }
};
