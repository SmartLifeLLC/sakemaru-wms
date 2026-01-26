<?php

namespace App\Console\Commands\TestData;

use Database\Seeders\AssignPickersToTasksSeeder;
use Database\Seeders\FloorSeeder;
use Database\Seeders\LocationSeeder;
use Database\Seeders\RealStockSeeder;
use Database\Seeders\WmsPickerSeeder;
use Database\Seeders\WmsPickingAreaSeeder;
use Illuminate\Console\Command;

class GenerateWmsTestDataCommand extends Command
{
    protected $signature = 'testdata:wms
                            {--warehouse-id=991 : Warehouse ID}
                            {--item-count=30 : Number of items to generate stock for}
                            {--all : Generate all WMS test data (picking areas, stocks, pickers)}
                            {--picking-areas : Generate picking areas only}
                            {--stocks : Generate stock data only}
                            {--pickers : Generate pickers only}
                            {--assign-pickers : Assign pickers to existing tasks only}';

    protected $description = 'Generate WMS test data (picking areas, locations, stocks, pickers)';

    public function handle()
    {
        $warehouseId = (int) $this->option('warehouse-id');
        $itemCount = (int) $this->option('item-count');

        $all = $this->option('all');
        $pickingAreas = $this->option('picking-areas');
        $stocks = $this->option('stocks');
        $pickers = $this->option('pickers');
        $assignPickers = $this->option('assign-pickers');

        // If no specific option is set, generate all
        if (! $all && ! $pickingAreas && ! $stocks && ! $pickers && ! $assignPickers) {
            $all = true;
        }

        $this->info('🏗️  Generating WMS test data...');
        $this->newLine();

        $exitCode = 0;

        try {
            // 0. Generate floors (prerequisite for locations)
            if ($all) {
                $this->info('🏢 Generating floors...');
                $seeder = new FloorSeeder;
                $seeder->setCommand($this);
                $seeder->run();
                $this->newLine();
            }

            // 0.5. Generate base locations (one per floor per warehouse)
            if ($all) {
                $this->info('📍 Generating base locations...');
                $seeder = new LocationSeeder;
                $seeder->setCommand($this);
                $seeder->run();
                $this->newLine();
            }

            // 1. Generate picking areas
            if ($all || $pickingAreas) {
                $this->info('📦 Generating picking areas...');
                $seeder = new WmsPickingAreaSeeder;
                $seeder->setCommand($this);
                $seeder->run();
                $this->newLine();
            }

            // 2. Generate stocks (also creates additional locations if needed)
            if ($all || $stocks) {
                $this->info('📊 Generating stock data...');
                $seeder = new RealStockSeeder;
                $seeder->setCommand($this);
                $seeder->run();
                $this->newLine();
            }

            // 3. Generate WMS pickers
            if ($all || $pickers) {
                $this->info('👤 Generating WMS pickers...');
                $seeder = new WmsPickerSeeder;
                $seeder->setCommand($this);
                $seeder->run();
                $this->newLine();
            }

            // 5. Assign pickers to tasks (if tasks exist)
            if ($all || $assignPickers) {
                $this->info('📋 Assigning pickers to tasks...');
                $seeder = new AssignPickersToTasksSeeder;
                $seeder->setCommand($this);
                $seeder->run();
                $this->newLine();
            }

            $this->info('✅ WMS test data generation completed!');
        } catch (\Exception $e) {
            $this->error('❌ Error generating WMS test data: '.$e->getMessage());
            $this->error($e->getTraceAsString());
            $exitCode = 1;
        }

        return $exitCode;
    }
}
