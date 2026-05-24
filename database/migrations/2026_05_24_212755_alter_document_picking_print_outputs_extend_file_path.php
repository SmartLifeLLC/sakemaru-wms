<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection('sakemaru')->table('document_picking_print_outputs', function (Blueprint $table) {
            $table->string('file_path', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('document_picking_print_outputs', function (Blueprint $table) {
            $table->string('file_path', 100)->nullable()->change();
        });
    }
};
