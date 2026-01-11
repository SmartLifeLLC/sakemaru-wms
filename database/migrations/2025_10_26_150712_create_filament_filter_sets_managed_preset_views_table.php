<?php

use Archilex\AdvancedTables\Support\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filament_filter_sets_managed_preset_views', function (Blueprint $table) {
            $table->id();

            // FK はあとで貼るので通常カラム
            $table->unsignedBigInteger('user_id');

            $table->string('name');
            $table->string('label')->nullable();
            $table->string('resource');
            $table->boolean('is_favorite')->default(true);
            $table->smallInteger('sort_order')->default(1);

            $table->timestamps();
        });

        // users（共有・prefixなし）への外部キー
        DB::statement("
        ALTER TABLE wms_filament_filter_sets_managed_preset_views
        ADD CONSTRAINT fk_wms_ffsm_preset_views_user_id
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
    ");
    }

    public function down(): void
    {
        DB::statement("
        ALTER TABLE wms_filament_filter_sets_managed_preset_views
        DROP FOREIGN KEY fk_wms_ffsm_preset_views_user_id
    ");

        Schema::dropIfExists('filament_filter_sets_managed_preset_views');
    }
};
