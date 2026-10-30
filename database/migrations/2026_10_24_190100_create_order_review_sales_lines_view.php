<?php

use App\Services\AutoOrder\OrderReviewSalesSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        DB::connection($this->connection)->statement('CREATE OR REPLACE SQL SECURITY INVOKER VIEW '.OrderReviewSalesSql::VIEW.' AS '.OrderReviewSalesSql::select());
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP VIEW IF EXISTS '.OrderReviewSalesSql::VIEW);
    }
};
