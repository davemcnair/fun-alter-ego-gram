<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class WordsFromResourcesSeeder extends Seeder
{
    public function run(): void
    {
        // Seed words from resources/token_words by invoking the existing importer command.
        // Directory layout expected:
        // resources/token_words/
        //   <token_type>/
        //     ok.txt | fun.txt | boring.txt (plain text, one word per line)
        $base = resource_path('token_words');
        $this->command?->info('Seeding words from: ' . $base);
        Artisan::call('words:import', ['base' => $base]);
        // Stream output to seeder console if available
        $this->command?->getOutput()->writeln(Artisan::output());
    }
}
