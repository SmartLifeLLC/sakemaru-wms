<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::table('print_request_queue', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('print_request_queue', function (Blueprint $table) {
            $table->dropColumn('requested_by');
        });
    }
};
