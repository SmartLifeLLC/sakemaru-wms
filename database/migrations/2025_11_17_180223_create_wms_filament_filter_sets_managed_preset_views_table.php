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
        //        Schema::connection('sakemaru')->create('wms_filament_filter_sets_managed_preset_views', function (Blueprint $table) {
        //            $userClass = Config::getUser();
        //            $user = new $userClass();
        //
        //            $table->id();
        //
        //            $table->foreignId('user_id')->references($user->getKeyName())->on($user->getTable())->constrained()->cascadeOnUpdate()->cascadeOnDelete();
        //            $table->string('name');
        //            $table->string('label')->nullable();
        //            $table->string('resource');
        //            $table->boolean('is_favorite')->default(true);
        //            $table->smallInteger('sort_order')->default(1);
        //
        //            $table->timestamps();
        //        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_filament_filter_sets_managed_preset_views');
    }
};
