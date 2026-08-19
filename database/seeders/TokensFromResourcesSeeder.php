<?php

namespace Database\Seeders;

use App\Support\NameNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class TokensFromResourcesSeeder extends Seeder
{

    public function run(): void
    {
        $basePath = resource_path('token_words');
        if (!File::exists($basePath)) {
            $this->command?->warn('token_words directory not found at ' . $basePath . '; skipping TokensFromResourcesSeeder');
            return;
        }

        $prioMap = [
            'surname' => 1,
            'forename' => 2,
            'title' => 3,
            'honorific' => 4,
            'prefix' => 5,
            'suffix' => 6,
            'initials' => 7,
        ];

        $dirs = array_values(array_filter(File::directories($basePath), fn($p) => File::isDirectory($p)));
        $summary = [];

        foreach ($dirs as $dir) {
            $name = basename($dir);
            $prio = $prioMap[$name] ?? 999;

            $okFile = $dir . DIRECTORY_SEPARATOR . 'ok.txt';
            $funFile = $dir . DIRECTORY_SEPARATOR . 'fun.txt';
            $boringFile = $dir . DIRECTORY_SEPARATOR . 'boring.txt';

            $readLines = function (string $filePath): array {
                if (!File::exists($filePath)) {
                    return [];
                }
                $content = File::get($filePath);
                $lines = [];
                foreach (preg_split('/\R/u', (string) $content) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
                return $lines;
            };

            $ok = $readLines($okFile);
            $fun = $readLines($funFile);
            $boring = $readLines($boringFile);

            $hasFun = count($fun) > 0;
            $hasBoring = count($boring) > 0;

            $all = array_merge($ok, $fun, $boring);
            $min = null;
            foreach ($all as $word) {
                $sig = NameNormalizer::anagramSignature($word)->signature;
                $len = strlen($sig);
                if ($len === 0) {
                    continue;
                }
                if ($min === null || $len < $min) {
                    $min = $len;
                }
            }
            $minLen = $min ?? 0;

            $maxMultiples = 1;
            if ($name === 'forename') {
                $maxMultiples = 2;
            }
            if ($name === 'surname') {
                $maxMultiples = 5;
            }

            DB::table('tokens')->updateOrInsert(
                ['name' => $name],
                [
                    'prio' => $prio,
                    'min_length' => $minLen,
                    'allow_nearly' => false,
                    'has_fun' => $hasFun,
                    'has_boring' => $hasBoring,
                    'max_multiples' => $maxMultiples,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $summary[] = compact('name', 'prio', 'minLen', 'hasFun', 'hasBoring', 'maxMultiples');
        }

        if ($this->command) {
            foreach ($summary as $s) {
                $this->command->info(sprintf(
                    'Seeded token: %s (prio=%d, min=%d, fun=%s, boring=%s, maxMultiples=%d)',
                    $s['name'], $s['prio'], $s['minLen'], $s['hasFun'] ? 'Y' : 'N', $s['hasBoring'] ? 'Y' : 'N', $s['maxMultiples']
                ));
            }
            $this->command->info('Seeded ' . count($summary) . ' tokens from resources/token_words.');
        }
    }
}
