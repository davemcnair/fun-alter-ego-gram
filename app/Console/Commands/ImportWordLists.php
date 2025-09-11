<?php

namespace App\Console\Commands;

use App\Models\Token;
use App\Services\WordMatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Log;

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
            $svc = app(WordMatchService::class);
            foreach (File::directories($basePath) as $tokenTypePath) {
                $tokenType = basename($tokenTypePath);
                Log::info($tokenType);
                $token = Token::where('name', $tokenType)->first();
                foreach (File::files($tokenTypePath) as $file) {
                    // $file is SplFileInfo
                    $listType = pathinfo($file->getFilename(), PATHINFO_FILENAME); // ok, fun, boring
                    $lines = @file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

                    foreach ($lines as $word) {
                        $svc->addTokenWord($tokenType, trim($word), $listType);
                    }
                }
            }
        });

        $this->info('Imported all word lists with normalization');
        return self::SUCCESS;
    }
}
