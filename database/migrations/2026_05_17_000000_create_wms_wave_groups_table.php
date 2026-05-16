<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('sakemaru')->hasTable('wms_wave_groups')) {
            Schema::connection('sakemaru')->table('wms_wave_groups', function (Blueprint $table) {
                if (! Schema::connection('sakemaru')->hasColumn('wms_wave_groups', 'generation_result')) {
                    $table->json('generation_result')->nullable()->after('conditions');
                }

                if (! Schema::connection('sakemaru')->hasColumn('wms_wave_groups', 'picking_lists')) {
                    $table->json('picking_lists')->nullable()->after('generation_result');
                }

                if (! Schema::connection('sakemaru')->hasColumn('wms_wave_groups', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('created_by');
                }

                if (! Schema::connection('sakemaru')->hasColumn('wms_wave_groups', 'cancelled_by')) {
                    $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
                }

                if (! Schema::connection('sakemaru')->hasColumn('wms_wave_groups', 'cancel_reason')) {
                    $table->text('cancel_reason')->nullable()->after('cancelled_by');
                }

                if (! Schema::connection('sakemaru')->hasColumn('wms_wave_groups', 'regenerated_from_wave_group_id')) {
                    $table->unsignedBigInteger('regenerated_from_wave_group_id')->nullable()->after('cancel_reason');
                    $table->index('regenerated_from_wave_group_id');
                }
            });

            return;
        }

        Schema::connection('sakemaru')->create('wms_wave_groups', function (Blueprint $table) {
            $table->id();
            $table->string('group_no', 40)->unique();
            $table->unsignedBigInteger('warehouse_id');
            $table->date('shipping_date');
            $table->string('generation_type', 50)->default('delivery_course');
            $table->json('target_document_types')->nullable();
            $table->json('conditions')->nullable();
            $table->json('generation_result')->nullable();
            $table->json('picking_lists')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->unsignedBigInteger('regenerated_from_wave_group_id')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'shipping_date']);
            $table->index('created_by');
            $table->index('regenerated_from_wave_group_id');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_wave_groups');
    }
};
