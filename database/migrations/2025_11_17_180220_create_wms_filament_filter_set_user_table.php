<?php

use Archilex\AdvancedTables\Support\Config;
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
//        Schema::connection('sakemaru')->create('wms_filament_filter_set_user', function (Blueprint $table) {
//            $userClass = Config::getUser();
//            $user = new $userClass();
//
//            $table->id();
//
//            $table->foreignId('user_id')->references($user->getKeyName())->on($user->getTable())->constrained()->cascadeOnDelete();
//            $table->foreignId('filter_set_id')->references('id')->on('wms_filament_filter_sets')->constrained()->cascadeOnDelete();
//            $table->smallInteger('sort_order')->default(1);
//        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_filament_filter_set_user');
    }
};
