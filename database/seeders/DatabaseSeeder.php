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
        // Seed tokens, then words and patterns from resources (order matters)
        $this->call([
            TokensFromResourcesSeeder::class,
            WordsFromResourcesSeeder::class,
            PatternsFromResourcesSeeder::class,
        ]);
    }
}
