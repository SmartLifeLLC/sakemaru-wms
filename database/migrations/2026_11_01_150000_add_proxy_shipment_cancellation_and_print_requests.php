<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (DB::connection($this->connection)->getDriverName() === 'mysql') {
            DB::connection($this->connection)->statement("ALTER TABLE wms_shortage_allocations MODIFY status ENUM('PENDING','RESERVED','PICKING','FULFILLED','SHORTAGE','CANCELLED') NULL DEFAULT 'PENDING'");
        }
        $schema->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_user_id')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->string('cancel_previous_status', 32)->nullable();
            $table->unsignedBigInteger('stock_transfer_queue_id')->nullable()->unique();
            $table->string('transfer_not_required_reason', 32)->nullable();
        });
        $schema->create('wms_proxy_shipment_print_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('requested_by');
            $table->uuid('idempotency_key');
            $table->string('selection_hash', 64);
            $table->unsignedInteger('current_attempt')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['client_id', 'requested_by', 'idempotency_key'], 'proxy_print_operation_unique');
        });
        $schema->create('wms_proxy_shipment_print_request_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('allocation_id');
            $table->unsignedBigInteger('stock_transfer_queue_id');
            $table->unsignedBigInteger('stock_transfer_id');
            $table->string('snapshot_hash', 64);
            $table->unique(['request_id', 'allocation_id'], 'proxy_print_allocation_unique');
        });
        $schema->create('wms_proxy_shipment_print_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedInteger('attempt');
            $table->unsignedBigInteger('log_pdf_export_id')->unique();
            $table->string('status', 32)->default('queued');
            $table->string('error_message', 500)->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
            $table->unique(['request_id', 'attempt'], 'proxy_print_attempt_unique');
        });
    }

    public function down(): void
    {
        // Keep cancellation history and print audit records during application rollback.
        // Removing CANCELLED or these tables would destroy historical business evidence.
    }
};
