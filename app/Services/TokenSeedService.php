<?php

namespace App\Services;

use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class TokenSeedService
{

    public function seedFromResources(string $basePath): array
    {
        if (!File::exists($basePath)) {
            throw new \RuntimeException("token_words directory not found: $basePath");
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

        $summary = [];
        $dirs = array_values(array_filter(File::directories($basePath), fn($p) => File::isDirectory($p)));

        foreach ($dirs as $dir) {
            $name = basename($dir);
            $prio = $prioMap[$name] ?? 999;

            $okFile = $dir . DIRECTORY_SEPARATOR . 'ok.txt';
            $funFile = $dir . DIRECTORY_SEPARATOR . 'fun.txt';
            $boringFile = $dir . DIRECTORY_SEPARATOR . 'boring.txt';

            $readLines = function ($filePath) {
                if (!File::exists($filePath)) return [];
                $content = File::get($filePath);
                $lines = [];
                foreach (preg_split('/\R/u', $content) as $line) {
                    $line = trim($line);
                    if ($line !== '') $lines[] = $line;
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
                if ($len === 0) continue;
                if ($min === null || $len < $min) $min = $len;
            }
            $minLen = $min ?? 0;

            $maxMultiples = 1;
            if ($name === 'forename') $maxMultiples = 2;
            if ($name === 'surname') $maxMultiples = 5;

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

        return $summary;
    }
}
