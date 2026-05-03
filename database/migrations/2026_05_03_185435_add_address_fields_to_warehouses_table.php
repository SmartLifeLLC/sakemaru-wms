<?php

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
        Schema::connection('sakemaru')->table('warehouses', function (Blueprint $table) {
            $table->string('tel', 20)->nullable()->after('address2');
            $table->string('fax', 20)->nullable()->after('tel');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['tel', 'fax']);
        });
    }
};
