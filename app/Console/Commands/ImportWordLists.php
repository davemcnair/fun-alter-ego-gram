<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Models\Word;
use App\Models\AnagramGroup;
use Illuminate\Support\Facades\DB;

class ImportWordLists extends Command
{
    protected $signature = 'words:import {base=storage/app/wordlists}';
    protected $description = 'Import all token-type word lists into the database';

    public function handle()
    {
        $basePath = $this->argument('base');

        if (!File::exists($basePath)) {
            $this->warn("Base path not found: {$basePath}");
            return self::FAILURE;
        }

        DB::transaction(function () use ($basePath) {
            foreach (File::directories($basePath) as $tokenTypePath) {
                $tokenType = basename($tokenTypePath);

                foreach (File::files($tokenTypePath) as $file) {
                    // $file is SplFileInfo
                    $listType = pathinfo($file->getFilename(), PATHINFO_FILENAME); // ok, fun, boring
                    $lines = @file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

                    foreach ($lines as $word) {
                        // Normalize: lowercase, remove punctuation (ASCII-only)
                        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $word));

                        // Signature: sorted letters (ASCII-only)
                        $letters = str_split($normalized);
                        sort($letters);
                        $signature = implode('', $letters);

                        // Skip storing words that normalize to an empty signature
                        if ($signature === '') {
                            continue;
                        }

                        // Ensure anagram group exists
                        $group = AnagramGroup::firstOrCreate(
                            ['token_type' => $tokenType, 'signature' => $signature],
                            ['words_count' => 0]
                        );

                        $w = Word::updateOrCreate(
                            [
                                'word' => $word,
                                'token_type' => $tokenType,
                                'list_type' => $listType,
                            ],
                            [
                                'signature' => $signature,
                                'anagram_group_id' => $group->id,
                            ]
                        );

                        // Increment words_count when newly attached/created
                        if ($w->wasRecentlyCreated || (int)$w->anagram_group_id !== (int)$group->id) {
                            $group->increment('words_count');
                        }
                    }
                }
            }
        });

        $this->info('Imported all word lists with normalization and anagram grouping.');
        return self::SUCCESS;
    }
}
