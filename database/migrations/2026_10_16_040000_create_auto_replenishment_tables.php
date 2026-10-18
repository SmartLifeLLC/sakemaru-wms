<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->create('wms_auto_order_controls', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id')->unique();
            $t->boolean('enabled')->default(false);
            $t->json('limits')->nullable();
            $t->unsignedInteger('generation')->default(1);
            $t->string('reset_state', 20)->default('PENDING');
            $t->string('release_key', 80)->nullable();
            $t->unsignedBigInteger('reset_cursor')->default(0);
            $t->unsignedBigInteger('reset_target_max')->default(0);
            $t->unsignedBigInteger('reset_count')->default(0);
            $t->timestamp('reset_completed_at')->nullable();
            $t->timestamps();
        });
        $schema->table('wms_warehouse_auto_order_settings', function (Blueprint $t) {
            $t->unsignedInteger('replenishment_version')->default(1);
            $t->json('replenishment_config')->nullable();
        });
        $schema->create('wms_item_reorder_policies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('item_id');
            $t->boolean('is_configured')->default(false);
            $t->boolean('is_enabled')->default(false);
            foreach (['reorder_point_pieces', 'max_order_pieces', 'min_order_pieces', 'order_multiple_pieces', 'target_stock_pieces'] as $c) {
                $t->unsignedBigInteger($c)->nullable();
            }
            $t->string('channel', 16)->nullable();
            $t->unsignedBigInteger('contractor_id')->nullable();
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->unsignedBigInteger('source_warehouse_id')->nullable();
            $t->unsignedBigInteger('delivery_course_id')->nullable();
            $t->string('strategy_key', 40)->default('threshold_v1');
            $t->json('arrival_rule')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['client_id', 'warehouse_id', 'item_id'], 'wms_reorder_policy_scope_unique');
            $t->index(['client_id', 'warehouse_id', 'is_enabled', 'id'], 'wms_reorder_policy_scan');
        });
        $schema->create('wms_auto_order_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('run_key')->unique();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->string('slot_key', 80);
            $t->boolean('is_preview')->default(false);
            $t->unsignedInteger('generation');
            $t->date('business_date');
            $t->string('status', 32)->default('RUNNING');
            $t->unsignedInteger('scanned_count')->default(0);
            $t->unsignedInteger('generated_count')->default(0);
            $t->unsignedInteger('held_count')->default(0);
            $t->unsignedInteger('failed_count')->default(0);
            $t->unsignedBigInteger('cursor')->default(0);
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->unique(['client_id', 'warehouse_id', 'slot_key'], 'wms_auto_run_slot_unique');
            $t->index(['client_id', 'warehouse_id', 'id'], 'wms_auto_run_history');
        });
        $schema->create('wms_auto_order_run_lines', function (Blueprint $t) {
            $t->id();
            $t->uuid('effect_key')->unique();
            $t->unsignedBigInteger('run_id');
            $t->unsignedBigInteger('policy_id');
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('item_id');
            $t->string('origin', 16)->default('AUTOMATIC');
            $t->string('channel', 16);
            $t->string('phase', 40);
            $t->string('reason', 500)->nullable();
            $t->unsignedBigInteger('quantity_pieces')->default(0);
            $t->decimal('amount', 20, 4)->default(0);
            $t->unsignedInteger('policy_version');
            $t->unsignedInteger('warehouse_version');
            $t->unsignedBigInteger('order_candidate_id')->nullable()->unique();
            $t->unsignedBigInteger('transfer_candidate_id')->nullable()->unique();
            $t->unsignedBigInteger('queue_id')->nullable()->index();
            $t->json('proposal_snapshot');
            $t->json('confirmation_snapshot')->nullable();
            $t->json('artifact_output_snapshot')->nullable();
            $t->timestamp('generated_at')->nullable();
            $t->timestamps();
            $t->unique(['run_id', 'policy_id']);
            $t->index(['client_id', 'warehouse_id', 'id'], 'wms_auto_line_history');
            $t->index(['client_id', 'warehouse_id', 'item_id', 'id'], 'wms_auto_line_supply');
            $t->index(['run_id', 'phase', 'id'], 'wms_auto_line_phase');
        });
        $schema->create('wms_auto_order_artifact_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('line_id');
            $t->string('artifact_type', 32);
            $t->unsignedBigInteger('artifact_id');
            $t->string('business_number', 100)->nullable();
            $t->json('snapshot')->nullable();
            $t->timestamps();
            $t->unique(['line_id', 'artifact_type', 'artifact_id'], 'wms_auto_artifact_unique');
            $t->index(['artifact_type', 'artifact_id'], 'wms_auto_artifact_reverse');
        });
        $schema->create('wms_replenishment_scopes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('item_id');
            $t->timestamps();
            $t->unique(['client_id', 'warehouse_id', 'item_id'], 'wms_replenishment_scope_unique');
        });
        $schema->create('wms_auto_order_budget_entries', function (Blueprint $t) {
            $t->id();
            $t->uuid('effect_key');
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('item_id');
            $t->string('state', 16)->default('RESERVED');
            $t->unsignedBigInteger('quantity_pieces');
            $t->decimal('amount', 20, 4);
            $t->timestamp('authorized_at');
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();
            $t->unique('effect_key');
            $t->index(['client_id', 'warehouse_id', 'state', 'committed_at'], 'wms_auto_budget_window');
            $t->index(['client_id', 'state', 'committed_at'], 'wms_auto_budget_client_window');
        });
        $schema->create('wms_order_generation_claims', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('candidate_id')->unique();
            $t->uuid('token');
            $t->char('manifest_hash', 64);
            $t->string('state', 16)->default('BUILDING');
            $t->unsignedBigInteger('document_id')->nullable();
            $t->timestamps();
        });
        $schema->create('wms_auto_order_transfer_receipts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('queue_id')->unique();
            $t->unsignedBigInteger('stock_transfer_id');
            $t->json('snapshot');
            $t->timestamp('created_at')->useCurrent();
        });
        $schema->create('wms_auto_order_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('line_id')->nullable()->index();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('event', 60);
            $t->json('payload');
            $t->timestamp('created_at')->useCurrent();
        });
        $schema->create('wms_auto_order_reset_rows', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->string('release_key', 80);
            $t->string('source_type', 32);
            $t->unsignedBigInteger('source_id');
            $t->json('snapshot');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['client_id', 'release_key', 'source_type', 'source_id'], 'wms_auto_reset_row_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Auto-order history must be retained. Disable execution instead of dropping tables.');
    }
};
