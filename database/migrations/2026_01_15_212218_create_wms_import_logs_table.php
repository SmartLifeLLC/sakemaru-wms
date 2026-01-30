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
        Schema::connection('sakemaru')->create('wms_import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->index(); // 'monthly_safety_stocks' etc.
            $table->string('status', 20)->default('pending'); // pending, processing, completed, failed
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_import_logs');
    }
};
