<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('locations', 'is_disabled')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->boolean('is_disabled')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('locations', 'is_disabled')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn('is_disabled');
            });
        }
    }
};
