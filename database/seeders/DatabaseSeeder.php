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
        // Seed words and patterns from resources
        $this->call([
            WordsFromResourcesSeeder::class,
            PatternsFromResourcesSeeder::class,
        ]);
    }
}
