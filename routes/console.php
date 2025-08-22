<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

Artisan::command('token_words:build {--save} {--dest=}', function () {
    $baseAltego = storage_path('app/altego');
    $baseToken = storage_path('app/token_words');

    if (!File::exists($baseToken)) {
        File::makeDirectory($baseToken, 0755, true);
    }

    if (!File::isDirectory($baseAltego)) {
        $this->error("Source directory not found: $baseAltego");
        return 1;
    }

    $dirs = array_values(array_filter(File::directories($baseAltego), function ($path) {
        return File::isDirectory($path);
    }));

    // Helper to read multiple files and merge lines
    $readMerge = function (array $paths) {
        $lines = [];
        foreach ($paths as $p) {
            if ($p && File::exists($p)) {
                $content = File::get($p);
                foreach (preg_split('/\R/u', $content) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            }
        }
        // Unique (case-insensitive), then sort (case-insensitive)
        $uniq = [];
        foreach ($lines as $l) {
            $key = mb_strtolower($l);
            $uniq[$key] = $l; // keep last occurrence's casing
        }
        $result = array_values($uniq);
        usort($result, function ($a, $b) {
            return strcasecmp($a, $b);
        });
        return $result;
    };

    $makeFile = function ($dir, $filename, array $lines) use ($baseToken) {
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        $target = $dir . DIRECTORY_SEPARATOR . $filename;
        File::put($target, implode(PHP_EOL, $lines) . (empty($lines) ? '' : PHP_EOL));
    };

    foreach ($dirs as $dirPath) {
        $group = basename($dirPath);
        $targetDir = $baseToken . DIRECTORY_SEPARATOR . $group;

        // Build lists based on rules
        $funnyFiles = [
            $dirPath . DIRECTORY_SEPARATOR . 'funny.txt',
            $dirPath . DIRECTORY_SEPARATOR . 'funny_boys.txt',
            $dirPath . DIRECTORY_SEPARATOR . 'funny_girls.txt',
        ];
        $remainderFiles = [
            $dirPath . DIRECTORY_SEPARATOR . 'remainder.txt',
            $dirPath . DIRECTORY_SEPARATOR . 'remainder_boys.txt',
            $dirPath . DIRECTORY_SEPARATOR . 'remainder_girls.txt',
        ];
        $boringFiles = [
            $dirPath . DIRECTORY_SEPARATOR . 'boring.txt',
            $dirPath . DIRECTORY_SEPARATOR . 'boring_boys.txt',
            $dirPath . DIRECTORY_SEPARATOR . 'boring_girls.txt',
        ];

        if ($group === 'forename') {
            $fun = $readMerge($funnyFiles);
            $ok = $readMerge($remainderFiles);
            $makeFile($targetDir, 'fun.txt', $fun);
            $makeFile($targetDir, 'ok.txt', $ok);
            $this->info("Built forename: fun.txt (" . count($fun) . "), ok.txt (" . count($ok) . ")");
        } elseif ($group === 'surname') {
            $fun = $readMerge($funnyFiles);
            $ok = $readMerge($remainderFiles);
            $boring = $readMerge($boringFiles);
            $makeFile($targetDir, 'fun.txt', $fun);
            $makeFile($targetDir, 'ok.txt', $ok);
            $makeFile($targetDir, 'boring.txt', $boring);
            $this->info("Built surname: fun.txt (" . count($fun) . "), ok.txt (" . count($ok) . "), boring.txt (" . count($boring) . ")");
        } else {
            // other groups: only remainder -> ok
            $ok = $readMerge($remainderFiles);
            $makeFile($targetDir, 'ok.txt', $ok);
            $this->info("Built $group: ok.txt (" . count($ok) . ")");
        }
    }

    // Optionally save a copy into the repository (version-controlled) directory
    $saveOpt = (bool) $this->option('save') || !is_null($this->option('dest'));
    if ($saveOpt) {
        $destArg = $this->option('dest');
        $destRel = $destArg !== null && $destArg !== '' ? $destArg : 'resources/token_words';
        $destPath = base_path($destRel);

        try {
            if (File::exists($destPath)) {
                // Replace fully to ensure removed items are reflected
                File::deleteDirectory($destPath);
            }
            File::makeDirectory($destPath, 0755, true);
            File::copyDirectory($baseToken, $destPath);
            $this->info("Saved a repo copy to: $destPath");
        } catch (\Throwable $e) {
            $this->error('Failed to save repo copy: ' . $e->getMessage());
            // keep non-zero return if the primary build succeeded; warn only
        }
    }

    $this->info('token_words build complete.');
    return 0;
})->purpose('Build token_words files from altego sources');

Artisan::command('tokens:seed', function () {
    $basePath = base_path('resources/token_words');

    if (!File::exists($basePath)) {
        $this->error("token_words directory not found: $basePath");
        return 1;
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

    $total = 0;

    $dirs = array_values(array_filter(File::directories($basePath), function ($path) {
        return File::isDirectory($path);
    }));

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

        // Compute min signature length across all present lists
        $all = array_merge($ok, $fun, $boring);
        $minLen = 0;
        if (!empty($all)) {
            $min = null;
            foreach ($all as $word) {
                $normalized = mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $word));
                $letters = function_exists('mb_str_split') ? mb_str_split($normalized) : (preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: []);
                sort($letters);
                $signature = implode('', $letters);
                $len = mb_strlen($signature);
                if ($min === null || $len < $min) {
                    $min = $len;
                }
            }
            $minLen = $min ?? 0;
        }

        // maxMultiples rule
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
        $this->info("Seeded token: $name (prio=$prio, min=$minLen, fun=" . ($hasFun?'Y':'N') . ", boring=" . ($hasBoring?'Y':'N') . ", maxMultiples=$maxMultiples)");
        $total++;
    }

    $this->info("Seeded $total tokens from resources/token_words.");
    return 0;
})->purpose('Seed tokens based on resources/token_words');
