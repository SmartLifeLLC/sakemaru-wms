<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        // Approved demand events (年末年始・お盆・GW・連休・任意) used by the auto-replenishment recommendation.
        Schema::connection('sakemaru')->create('wms_demand_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedSmallInteger('year');
            $table->string('type', 16);
            $table->string('name', 60);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedTinyInteger('pre_days')->default(0);
            $table->unsignedTinyInteger('post_days')->default(0);
            $table->string('status', 12)->default('DRAFT');
            $table->string('note', 255)->nullable();
            // Weekly pipeline: learned-factor version bound to the event, backtest on past instances, automatic approval flag.
            $table->date('factor_trained_through')->nullable();
            $table->json('backtest')->nullable();
            $table->boolean('auto_approved')->default(false);
            $table->string('review_note', 255)->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'type', 'start_date'], 'wms_demand_events_scope');
            $table->index(['client_id', 'status', 'start_date'], 'wms_demand_events_status');
        });
        // Learned event factors per product (ITEM) or product category (CATEGORY = items.item_category3_id).
        // stage: ACTIVE = used by the recommendation; CANDIDATE = latest learning result awaiting a passed backtest or an approval.
        Schema::connection('sakemaru')->create('wms_demand_event_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('profile_key', 60);
            $table->string('scope_type', 10);
            $table->unsignedBigInteger('scope_id');
            $table->string('stage', 10)->default('ACTIVE');
            $table->json('positions');
            $table->decimal('pieces', 14, 2)->default(0);
            $table->unsignedSmallInteger('instance_count')->default(0);
            $table->date('trained_through');
            $table->string('model_version', 32);
            $table->timestamps();
            $table->unique(['client_id', 'profile_key', 'scope_type', 'scope_id', 'stage'], 'wms_demand_event_profiles_scope');
            $table->index(['client_id', 'stage', 'scope_type', 'scope_id'], 'wms_demand_event_profiles_lookup');
        });
        // Which standard events apply to which product (set at release, maintained by hand). No row = the event is not applied.
        Schema::connection('sakemaru')->create('wms_item_demand_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('item_id');
            $table->string('event_type', 16);
            $table->boolean('is_enabled')->default(true);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'item_id', 'event_type'], 'wms_item_demand_events_scope');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_item_demand_events');
        Schema::connection('sakemaru')->dropIfExists('wms_demand_event_profiles');
        Schema::connection('sakemaru')->dropIfExists('wms_demand_events');
    }
};
