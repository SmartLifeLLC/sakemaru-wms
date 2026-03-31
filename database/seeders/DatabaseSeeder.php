<?php

namespace Database\Seeders;

use App\Models\Sakemaru\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Seed Wave Settings
        $this->call([
            OrangeWarehouseSeeder::class,
            WaveSettingSeeder::class,
            WarehouseStockTransferDeliveryCourseSeeder::class,
        ]);
    }
}
