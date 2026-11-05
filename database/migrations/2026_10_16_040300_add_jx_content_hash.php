<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_order_jx_documents', function (Blueprint $table) {
            $table->char('content_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        // Preserve the verification evidence through application rollback.
    }
};
