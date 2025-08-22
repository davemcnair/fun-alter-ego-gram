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

Artisan::command('patterns:generate {--dry-run} {--print=20}', function () {
    // Load tokens and their limits
    $tokens = DB::table('tokens')->get()->keyBy('name');

    $getMax = function (string $name, int $default) use ($tokens) {
        $t = $tokens->get($name);
        return $t ? (int) $t->max_multiples : $default;
    };

    $getMinLen = function (string $name, int $default = 0) use ($tokens) {
        $t = $tokens->get($name);
        return $t ? (int) $t->min_length : $default;
    };

    $exists = function (string $name) use ($tokens) {
        return (bool) $tokens->get($name);
    };

    // Required core tokens
    if (!$exists('surname')) {
        $this->error('Missing required token: surname');
        return 1;
    }

    // Determine ranges from tokens table (falling back to sensible defaults)
    $maxForename = $getMax('forename', 2);
    $maxSurname  = $getMax('surname', 5);

    $hasTitle     = $exists('title');
    $hasInitials  = $exists('initials');
    $hasPrefix    = $exists('prefix');
    $hasSuffix    = $exists('suffix');
    $hasHonorific = $exists('honorific');

    $patterns = [];

    // Helper to build template string in the canonical order
    $buildTemplate = function (int $title, int $fn, int $ini, int $pre, int $sn, int $suf, int $hon) {
        $parts = [];
        if ($title > 0) $parts[] = '{title}';
        if ($fn > 0) $parts[] = $fn === 1 ? '{forename}' : '{forename:' . $fn . '}';
        if ($ini > 0) $parts[] = '{initials}';
        if ($pre > 0) $parts[] = '{prefix}';
        if ($sn > 0) $parts[] = $sn === 1 ? '{surname}' : '{surname:' . $sn . '}';
        if ($suf > 0) $parts[] = '{suffix}';
        if ($hon > 0) $parts[] = '{honorific}';
        return implode('', $parts);
    };

    // Enumerate combinations within constraints
    for ($sn = 1; $sn <= $maxSurname; $sn++) { // surname must exist
        for ($fn = 0; $fn <= $maxForename; $fn++) {
            for ($title = 0; $title <= ($hasTitle ? 1 : 0); $title++) {
                for ($ini = 0; $ini <= ($hasInitials ? 1 : 0); $ini++) {
                    for ($pre = 0; $pre <= ($hasPrefix ? 1 : 0); $pre++) {
                        for ($suf = 0; $suf <= ($hasSuffix ? 1 : 0); $suf++) {
                            for ($hon = 0; $hon <= ($hasHonorific ? 1 : 0); $hon++) {
                                // Adjacency rules: prefix and suffix only make sense with a surname present
                                // We already guarantee $sn >= 1, but keep explicit guards for clarity
                                if ($pre > 0 && $sn === 0) continue;
                                if ($suf > 0 && $sn === 0) continue;

                                // Rule: forename:2 may not be followed by initials
                                if ($fn >= 2 && $ini > 0) continue;

                                // Enforce: minimum distinct token types in a pattern is 2
                                $typesCount = 0;
                                if ($title > 0) $typesCount++;
                                if ($fn > 0) $typesCount++;
                                if ($ini > 0) $typesCount++;
                                if ($pre > 0) $typesCount++;
                                if ($sn > 0) $typesCount++;
                                if ($suf > 0) $typesCount++;
                                if ($hon > 0) $typesCount++;
                                if ($typesCount < 2) continue;

                                $template = $buildTemplate($title, $fn, $ini, $pre, $sn, $suf, $hon);
                                $patterns[$template] = [
                                    'title' => $title,
                                    'fn' => $fn,
                                    'ini' => $ini,
                                    'pre' => $pre,
                                    'sn' => $sn,
                                    'suf' => $suf,
                                    'hon' => $hon,
                                ];
                            }
                        }
                    }
                }
            }
        }
    }

    // Scoring heuristic: lower score => earlier (more common)
    $scoreOf = function (string $tpl, array $p) {
        $segments = ($p['title']>0) + ($p['fn']>0 ? $p['fn'] : 0) + ($p['ini']>0) + ($p['pre']>0) + ($p['sn']>0 ? $p['sn'] : 0) + ($p['suf']>0) + ($p['hon']>0);
        $score = 0;
        // Base by total segments: fewer first
        $score += $segments * 10;
        // Penalties for less common tokens
        if ($p['title']>0) $score += 3;
        // Make initials significantly rarer
        if ($p['ini']>0)   $score += 20;
        if ($p['pre']>0)   $score += 8;
        if ($p['suf']>0)   $score += 5;
        if ($p['hon']>0)   $score += 9;
        // Multiples slightly penalized (baseline)
        if ($p['fn']>1) $score += ($p['fn']-1) * 2;
        if ($p['sn']>1) $score += ($p['sn']-1) * 2;
        // Extra penalty: long surname chains are uncommon
        if ($p['sn'] >= 3) {
            $score += match($p['sn']) {
                3 => 25,
                4 => 45,
                default => 65, // 5 or more
            };
        }
        // Strongly prefer having a forename
        if ($p['fn']===0) $score += 30;
        // Ensure exact top-3 as requested
        if ($tpl === '{forename}{surname}') return -1000; // top 1
        if ($tpl === '{forename}{surname:2}') return -999; // top 2
        if ($tpl === '{title}{forename}{surname}') return -998; // top 3
        return $score;
    };

    // Build sortable list
    $list = [];
    foreach ($patterns as $tpl => $p) {
        $list[] = [
            'template' => $tpl,
            'meta' => $p,
            'score' => $scoreOf($tpl, $p),
        ];
    }

    usort($list, function ($a, $b) {
        if ($a['score'] === $b['score']) {
            // Tie-breakers: shorter string, then lexicographical
            $la = strlen($a['template']);
            $lb = strlen($b['template']);
            if ($la === $lb) return strcmp($a['template'], $b['template']);
            return $la <=> $lb;
        }
        return $a['score'] <=> $b['score'];
    });

    $dry = (bool) $this->option('dry-run');
    $printN = (int) $this->option('print');

    // Pre-compute token min lengths for min_total_length aggregation
    $minLenTitle     = $getMinLen('title', 0);
    $minLenForename  = $getMinLen('forename', 0);
    $minLenInitials  = $getMinLen('initials', 0);
    $minLenPrefix    = $getMinLen('prefix', 0);
    $minLenSurname   = $getMinLen('surname', 0);
    $minLenSuffix    = $getMinLen('suffix', 0);
    $minLenHonorific = $getMinLen('honorific', 0);

    if ($dry) {
        $this->info('Dry run: generated ' . count($list) . ' patterns.');
    } else {
        // Replace all existing records with the new ordering
        try {
            DB::table('patterns')->delete();
        } catch (\Throwable $e) {
            // ignore
        }
        $rank = 1;
        $now = now();
        $batch = [];
        foreach ($list as $row) {
            $p = $row['meta'];

            // Compute min_total_length using token min lengths and counts
            $minTotal = 0;
            if ($p['title'] > 0) $minTotal += $minLenTitle;
            if ($p['fn'] > 0)    $minTotal += $minLenForename * $p['fn'];
            if ($p['ini'] > 0)   $minTotal += $minLenInitials;
            if ($p['pre'] > 0)   $minTotal += $minLenPrefix;
            if ($p['sn'] > 0)    $minTotal += $minLenSurname * $p['sn'];
            if ($p['suf'] > 0)   $minTotal += $minLenSuffix;
            if ($p['hon'] > 0)   $minTotal += $minLenHonorific;

            $batch[] = [
                'template' => $row['template'],
                'popularity_rank' => $rank++,
                'min_total_length' => $minTotal,
                'forename_count' => $p['fn'],
                'surname_count' => $p['sn'],
                'has_title' => $p['title']>0,
                'has_initials' => $p['ini']>0,
                'has_prefix' => $p['pre']>0,
                'has_suffix' => $p['suf']>0,
                'has_honorific' => $p['hon']>0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            // Insert in chunks to avoid parameter limits if list grows
            if (count($batch) >= 500) {
                DB::table('patterns')->insert($batch);
                $batch = [];
            }
        }
        if (!empty($batch)) DB::table('patterns')->insert($batch);
        $this->info('Stored ' . ($rank-1) . ' patterns.');
    }

    if ($printN > 0) {
        $this->line('Top ' . $printN . ' patterns:');
        for ($i = 0; $i < min($printN, count($list)); $i++) {
            $row = $list[$i];
            $this->line(sprintf('%4d. %s', $i+1, $row['template']));
        }
    }

    return 0;
})->purpose('Generate exhaustive name pattern templates honoring ordering and adjacency rules');

Artisan::command('patterns:list {--limit=20} {--page=1} {--like=}', function () {
    $limit = (int) $this->option('limit');
    $page  = (int) $this->option('page');
    $like  = (string) $this->option('like');

    if ($limit < 1) $limit = 20;
    if ($page < 1) $page = 1;

    $query = DB::table('patterns')->orderBy('popularity_rank');
    if ($like !== null && $like !== '') {
        $query->where('template', 'like', '%' . $like . '%');
    }

    $total = (clone $query)->count();
    if ($total === 0) {
        $this->info('No patterns found' . ($like ? ' matching "' . $like . '"' : '') . '.');
        return 0;
    }

    $pages = (int) ceil($total / $limit);
    $offset = ($page - 1) * $limit;

    $rows = $query->offset($offset)->limit($limit)->get();

    $this->line('Total: ' . $total . ' | Page ' . $page . ' of ' . $pages . ' | Showing ' . count($rows) . ' (limit ' . $limit . ')');
    foreach ($rows as $row) {
        $this->line(sprintf('%5d. %s (min=%d)', $row->popularity_rank, $row->template, $row->min_total_length ?? 0));
    }

    return 0;
})->purpose('List stored patterns with optional filtering and pagination');
