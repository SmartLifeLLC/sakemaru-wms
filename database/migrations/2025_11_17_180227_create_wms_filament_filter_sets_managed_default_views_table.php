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
        Schema::connection('sakemaru')->create('wms_filament_filter_sets_managed_default_views', function (Blueprint $table) {
            $userClass = Config::getUser();
            $user = new $userClass();

            $table->id();

            $table->foreignId('user_id')->references($user->getKeyName())->on($user->getTable())->constrained()->cascadeOnDelete();
            $table->integer('tenant_id')->nullable();
            $table->string('resource');
            $table->string('view_type');
            $table->string('view');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_filament_filter_sets_managed_default_views');
    }
};
