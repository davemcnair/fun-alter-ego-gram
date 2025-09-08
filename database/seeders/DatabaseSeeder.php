<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // todo: seed words from resources if available
        // Seed patterns from resources if available
        $this->call(PatternsFromResourcesSeeder::class);
    }
}
