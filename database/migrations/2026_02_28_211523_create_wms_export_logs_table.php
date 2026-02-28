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
        Schema::connection('sakemaru')->create('wms_export_logs', function (Blueprint $table) {
            $table->id();
            $table->string('resource_name');
            $table->string('format', 10);
            $table->string('status', 20)->default('pending');
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->json('filters')->nullable();
            $table->json('columns')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('error_message')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['resource_name', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_export_logs');
    }
};
