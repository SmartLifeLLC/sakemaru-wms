<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filament_filter_sets_managed_default_views', function (Blueprint $table) {
            $table->id();

            // FK は後で貼る
            $table->unsignedBigInteger('user_id');

            $table->integer('tenant_id')->nullable();
            $table->string('resource');
            $table->string('view_type');
            $table->string('view');

            $table->timestamps();
        });

        // users（共有・prefixなし）への外部キー
        DB::statement('
            ALTER TABLE wms_filament_filter_sets_managed_default_views
            ADD CONSTRAINT fk_wms_ffsm_default_views_user_id
            FOREIGN KEY (user_id)
            REFERENCES users(id)
            ON DELETE CASCADE
        ');
    }

    public function down(): void
    {
        DB::statement('
            ALTER TABLE wms_filament_filter_sets_managed_default_views
            DROP FOREIGN KEY fk_wms_ffsm_default_views_user_id
        ');

        Schema::dropIfExists('filament_filter_sets_managed_default_views');
    }
};
