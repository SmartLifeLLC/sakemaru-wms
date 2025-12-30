<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_contractor_suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contractor_id')->comment('発注先ID');
            $table->unsignedBigInteger('supplier_id')->comment('仕入先ID');
            $table->string('memo', 255)->nullable()->comment('メモ');
            $table->timestamps();

            $table->unique(['contractor_id', 'supplier_id'], 'uk_contractor_supplier');
            $table->index('supplier_id', 'idx_supplier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_contractor_suppliers');
    }
};
