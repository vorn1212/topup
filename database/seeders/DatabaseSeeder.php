<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            StaffSeeder::class,
        ]);

        // Fictional sample games and products: never in production.
        if (! app()->isProduction()) {
            $this->call(CatalogSeeder::class);
        }
    }
}