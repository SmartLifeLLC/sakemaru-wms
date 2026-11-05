<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_proxy_shipment_print_requests', function (Blueprint $table) {
            $table->string('copy_type', 16)->default('both');
        });
    }

    public function down(): void
    {
        // Preserve the selected output type in the print audit history.
    }
};
