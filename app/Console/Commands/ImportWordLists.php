<?php

namespace App\Console\Commands;

use App\Services\WordCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

class ImportWordLists extends Command
{
    protected $signature = 'words:import {base=storage/app/wordlists}';
    protected $description = 'Import token-type word lists (plain text) into the database. Example bases: resources/wordlists or storage/app/wordlists';

    public function handle()
    {
        $basePath = $this->argument('base');

        if (!File::exists($basePath)) {
            $this->warn("Base path not found: {$basePath}");
            return self::FAILURE;
        }
        DB::transaction(function () use ($basePath) {
            $committedAt = now();
            $svc = app(WordCatalog::class);
            foreach (File::directories($basePath) as $tokenTypePath) {
                $tokenType = basename($tokenTypePath);
                foreach (File::files($tokenTypePath) as $file) {
                    // $file is SplFileInfo
                    $listType = pathinfo($file->getFilename(), PATHINFO_FILENAME); // ok, fun, boring
                    $lines = @file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

                    foreach ($lines as $word) {
                        $svc->add($tokenType, trim($word), $listType, $committedAt);
                    }
                }
            }
        });

        $this->info('Imported all word lists with normalization');
        return self::SUCCESS;
    }
}
