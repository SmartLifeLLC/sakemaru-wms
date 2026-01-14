<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateTestShortages extends Command
{
    protected $signature = 'test:generate-shortages {count=1000}';

    protected $description = 'Generate test shortage records for performance testing';

    public function handle()
    {
        $count = (int) $this->argument('count');

        $this->info("Generating {$count} test shortage records...");

        // Get available IDs
        $waveIds = DB::connection('sakemaru')->table('wms_waves')->pluck('id')->toArray();
        $warehouseIds = DB::connection('sakemaru')->table('warehouses')->where('is_active', 1)->pluck('id')->toArray();
        $itemIds = DB::connection('sakemaru')->table('items')->where('is_active', 1)->limit(100)->pluck('id')->toArray();
        $tradeIds = DB::connection('sakemaru')->table('trades')->where('is_active', 1)->limit(100)->pluck('id')->toArray();

        if (empty($waveIds) || empty($warehouseIds) || empty($itemIds) || empty($tradeIds)) {
            $this->error('Not enough master data available!');

            return 1;
        }

        $this->info('Available data:');
        $this->info('  Waves: '.count($waveIds));
        $this->info('  Warehouses: '.count($warehouseIds));
        $this->info('  Items: '.count($itemIds));
        $this->info('  Trades: '.count($tradeIds));

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $statuses = ['OPEN', 'REALLOCATING', 'FULFILLED', 'CONFIRMED'];
        $qtyTypes = ['CASE', 'PIECE', 'CARTON'];
        $batchSize = 100;
        $records = [];

        DB::connection('sakemaru')->transaction(function () use ($count, $waveIds, $warehouseIds, $itemIds, $tradeIds, $statuses, $qtyTypes, $batchSize, &$records, $bar) {
            for ($i = 0; $i < $count; $i++) {
                $shortageQty = rand(1, 100);
                $caseSize = rand(6, 24);

                $records[] = [
                    'wave_id' => $waveIds[array_rand($waveIds)],
                    'warehouse_id' => $warehouseIds[array_rand($warehouseIds)],
                    'item_id' => $itemIds[array_rand($itemIds)],
                    'trade_id' => $tradeIds[array_rand($tradeIds)],
                    'trade_item_id' => rand(1, 10000),
                    'order_qty_each' => rand(10, 500),
                    'planned_qty_each' => rand(10, 500),
                    'picked_qty_each' => rand(0, 300),
                    'shortage_qty_each' => $shortageQty,
                    'allocation_shortage_qty' => rand(0, 50),
                    'picking_shortage_qty' => rand(0, 50),
                    'qty_type_at_order' => $qtyTypes[array_rand($qtyTypes)],
                    'case_size_snap' => $caseSize,
                    'status' => $statuses[array_rand($statuses)],
                    'created_at' => now()->subDays(rand(0, 30))->subHours(rand(0, 23)),
                    'updated_at' => now(),
                ];

                // Insert in batches
                if (count($records) >= $batchSize) {
                    DB::connection('sakemaru')->table('wms_shortages')->insert($records);
                    $bar->advance(count($records));
                    $records = [];
                }
            }

            // Insert remaining records
            if (! empty($records)) {
                DB::connection('sakemaru')->table('wms_shortages')->insert($records);
                $bar->advance(count($records));
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Successfully generated {$count} test shortage records!");

        // Show summary
        $this->info("\nStatus distribution:");
        $distribution = DB::connection('sakemaru')
            ->table('wms_shortages')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        foreach ($distribution as $stat) {
            $this->info("  {$stat->status}: {$stat->count}");
        }

        return 0;
    }
}
