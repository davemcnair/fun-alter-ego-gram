<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Models\Word;

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

        foreach (File::directories($basePath) as $tokenTypePath) {
            $tokenType = basename($tokenTypePath);

            foreach (File::files($tokenTypePath) as $file) {
                // $file is SplFileInfo
                $listType = pathinfo($file->getFilename(), PATHINFO_FILENAME); // ok, fun, boring
                $lines = @file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

                foreach ($lines as $word) {
                    // Normalize: lowercase, remove punctuation
                    $normalized = mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $word));

                    // Signature: sorted letters (multibyte-aware)
                    $letters = $this->mbStringSplit($normalized);
                    sort($letters);
                    $signature = implode('', $letters);

                    Word::updateOrCreate(
                        [
                            'word' => $word,
                            'token_type' => $tokenType,
                            'list_type' => $listType,
                        ],
                        ['signature' => $signature]
                    );
                }
            }
        }

        $this->info('Imported all word lists with normalization.');
        return self::SUCCESS;
    }

    /**
     * Split a multibyte string into an array of characters.
     * Falls back to preg_split for environments without mb_str_split (PHP < 7.4).
     *
     * @param string $string
     * @return array<int, string>
     */
    protected function mbStringSplit(string $string): array
    {
        if (function_exists('mb_str_split')) {
            return mb_str_split($string);
        }
        $result = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        return $result === false ? [] : $result;
    }
}
