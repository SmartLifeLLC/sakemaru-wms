<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_incoming_received_files', function (Blueprint $table) {
            $table->string('a_created_date', 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_incoming_received_files', function (Blueprint $table) {
            $table->string('a_created_date', 6)->nullable()->change();
        });
    }
};
