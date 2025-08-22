<?php

// Console routes were previously defined here as closures.
// They've been extracted into dedicated Command classes under app/Console/Commands
// and reusable services under app/Services to support controller reuse.
/*
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
            $key = strtolower($l);
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
                $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $word));
                $letters = str_split($normalized);
                sort($letters);
                $signature = implode('', $letters);
                $len = strlen($signature);
                if ($len === 0) continue; // ignore empty signatures entirely
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

                                // New rules:
                                // - patterns with surname must also contain one of: title, forename, initials
                                if ($sn > 0 && ($title === 0 && $fn === 0 && $ini === 0)) continue;
                                // - patterns with forename must also contain one of: title, surname, honorific
                                if ($fn > 0 && ($title === 0 && $sn === 0 && $hon === 0)) continue;

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

Artisan::command('patterns:list {--limit=20} {--page=1} {--like=} {--source=} {--dynamic} {--list=} {--include-boring} {--filter-empty-only}', function () {
    $limit = (int) $this->option('limit');
    $page  = (int) $this->option('page');
    $like  = (string) $this->option('like');
    $source = (string) $this->option('source');
    $useDynamic = (bool) $this->option('dynamic');
    $filterList = (string) $this->option('list');
    $includeBoring = (bool) $this->option('include-boring');
    $filterEmptyOnly = (bool) $this->option('filter-empty-only');

    if ($limit < 1) $limit = 20;
    if ($page < 1) $page = 1;

    // Helpers for normalization/signature and subset check (ASCII-only)
    $normalize = function (string $s): string {
        $s = strtolower($s);
        return preg_replace('/[^a-z0-9]/i', '', $s) ?? '';
    };
    $makeSignature = function (string $s) use ($normalize): string {
        $norm = $normalize($s);
        $chars = str_split($norm);
        sort($chars);
        return implode('', $chars);
    };
    $isSubset = function (string $small, string $big): bool {
        // both are sorted ASCII strings
        $i = 0; $j = 0; $ls = strlen($small); $lb = strlen($big);
        if ($ls === 0) return true;
        while ($i < $ls && $j < $lb) {
            $cs = $small[$i];
            $cb = $big[$j];
            if ($cs === $cb) { $i++; $j++; }
            elseif ($cs > $cb) { $j++; }
            else { return false; }
        }
        return $i === $ls;
    };

    $srcSig = '';
    $srcLen = null; // null means no source filtering
    if ($source !== null && $source !== '') {
        $srcSig = $makeSignature($source);
        $srcLen = strlen($srcSig);
    }

    // Optionally compute dynamic effective token min lengths for this source
    $effectiveMin = [];
    $tokenNames = ['title','forename','initials','prefix','surname','suffix','honorific'];
    $usedDynamic = false;
    if (($useDynamic || $filterEmptyOnly) && $srcLen !== null && $srcLen > 0) {
        $usedDynamic = true;
        // Build base words query according to list options
        $base = DB::table('words')->select('id','token_type','signature');
        if ($filterList !== '') {
            $base->where('list_type', $filterList);
        } else {
            if (!$includeBoring) {
                $base->where('list_type', '!=', 'boring');
            }
        }
        // Iterate in chunks to derive per-token minimum signature length among words that fit inside the source signature and aren't longer than it
        $mins = [];
        $base->orderBy('id');
        $base->chunkById(1000, function ($rows) use (&$mins, $srcSig, $srcLen, $isSubset) {
            foreach ($rows as $r) {
                $sig = (string)($r->signature ?? '');
                $len = strlen($sig);
                if ($srcLen !== null && $len > $srcLen) continue;
                if (!$isSubset($sig, $srcSig)) continue;
                $tok = (string)$r->token_type;
                if (!isset($mins[$tok]) || $len < $mins[$tok]) {
                    $mins[$tok] = $len;
                }
            }
        }, 'id');
        foreach ($tokenNames as $tn) {
            $effectiveMin[$tn] = $mins[$tn] ?? null; // null means no matching word for that token
        }
    }

    // Base query for patterns
    $query = DB::table('patterns')->orderBy('popularity_rank');
    if ($like !== null && $like !== '') {
        $query->where('template', 'like', '%' . $like . '%');
    }

    // Apply preliminary static filtering if only source is provided without dynamic
    if ($srcLen !== null && $srcLen >= 0) {
        // Apply static length prefilter unless we're in full dynamic-length mode
        if (!$usedDynamic || $filterEmptyOnly) {
            $query->where('min_total_length', '<=', $srcLen);
        }
    }

    // Fetch rows first (we might need to do dynamic per-row checks)
    $allRows = $query->get();

    // If dynamic filtering is requested, filter in-memory using effective token mins
    $rows = $allRows;
    $filteredCount = null;
    if ($usedDynamic) {
        $rows = $allRows->filter(function ($row) use ($effectiveMin, $srcLen, $filterEmptyOnly) {
            // If any required token has no matches (null), the pattern cannot be satisfied
            $min = 0;
            // title
            if ($row->has_title) {
                if ($effectiveMin['title'] === null) return false;
                if (!$filterEmptyOnly) $min += $effectiveMin['title'];
            }
            // forename
            if (($row->forename_count ?? 0) > 0) {
                if ($effectiveMin['forename'] === null) return false;
                if (!$filterEmptyOnly) $min += $effectiveMin['forename'] * (int)$row->forename_count;
            }
            // initials
            if ($row->has_initials) {
                if ($effectiveMin['initials'] === null) return false;
                if (!$filterEmptyOnly) $min += $effectiveMin['initials'];
            }
            // prefix
            if ($row->has_prefix) {
                if ($effectiveMin['prefix'] === null) return false;
                if (!$filterEmptyOnly) $min += $effectiveMin['prefix'];
            }
            // surname
            if (($row->surname_count ?? 0) > 0) {
                if ($effectiveMin['surname'] === null) return false;
                if (!$filterEmptyOnly) $min += $effectiveMin['surname'] * (int)$row->surname_count;
            }
            // suffix
            if ($row->has_suffix) {
                if ($effectiveMin['suffix'] === null) return false;
                if (!$filterEmptyOnly) $min += $effectiveMin['suffix'];
            }
            // honorific
            if ($row->has_honorific) {
                if ($effectiveMin['honorific'] === null) return false;
                if (!$filterEmptyOnly) $min += $effectiveMin['honorific'];
            }
            if ($filterEmptyOnly) return true; // availability-only mode
            return $srcLen === null ? true : ($min <= $srcLen);
        })->values();
        $filteredCount = count($rows);
    }

    $total = count($rows);
    if ($total === 0) {
        $suffix = $like ? ' matching "' . $like . '"' : '';
        if ($srcLen !== null) $suffix .= ' for source length ' . $srcLen;
        if ($usedDynamic) $suffix .= ' (dynamic)';
        $this->info('No patterns found' . $suffix . '.');
        return 0;
    }

    $pages = (int) ceil($total / $limit);
    $offset = ($page - 1) * $limit;

    $rowsPage = collect($rows)->slice($offset, $limit)->values();

    $header = 'Total: ' . $total . ' | Page ' . $page . ' of ' . $pages . ' | Showing ' . count($rowsPage) . ' (limit ' . $limit . ')';
    if ($srcLen !== null) {
        $mode = $usedDynamic ? ($filterEmptyOnly ? ' (avail-only)' : ' (dynamic)') : '';
        $header .= ' | source_len=' . $srcLen . $mode;
        if ($filterList !== '') $header .= ' | list=' . $filterList;
        elseif (!$includeBoring) $header .= ' | boring=excluded';
    }
    $this->line($header);

    foreach ($rowsPage as $row) {
        if ($usedDynamic) {
            if ($filterEmptyOnly) {
                $this->line(sprintf('%5d. %s (avail)', $row->popularity_rank, $row->template));
            } else {
                // recompute dynamic min for display (cheap re-do similar to above)
                $min = 0;
                if ($row->has_title) $min += $effectiveMin['title'] ?? 0;
                if (($row->forename_count ?? 0) > 0) $min += ($effectiveMin['forename'] ?? 0) * (int)$row->forename_count;
                if ($row->has_initials) $min += $effectiveMin['initials'] ?? 0;
                if ($row->has_prefix) $min += $effectiveMin['prefix'] ?? 0;
                if (($row->surname_count ?? 0) > 0) $min += ($effectiveMin['surname'] ?? 0) * (int)$row->surname_count;
                if ($row->has_suffix) $min += $effectiveMin['suffix'] ?? 0;
                if ($row->has_honorific) $min += $effectiveMin['honorific'] ?? 0;
                $this->line(sprintf('%5d. %s (dyn_min=%d)', $row->popularity_rank, $row->template, $min));
            }
        } else {
            $this->line(sprintf('%5d. %s (min=%d)', $row->popularity_rank, $row->template, $row->min_total_length ?? 0));
        }
    }

    return 0;
})->purpose('List stored patterns with optional filtering and pagination (supports --source and dynamic min filtering)');

Artisan::command('words:matches {source*} {--token=} {--list=} {--json} {--include-boring}', function () {
    // Join source args back into a single string (allows spaces without quotes)
    $sourceParts = (array) $this->argument('source');
    $sourceName = trim(implode(' ', $sourceParts));
    if ($sourceName === '') {
        $this->error('Please provide a source name, e.g. php artisan words:matches "First Middle Last"');
        return 1;
    }

    $filterToken = (string) $this->option('token');
    $filterList  = (string) $this->option('list');
    $asJson      = (bool) $this->option('json');
    $includeBoring = (bool) $this->option('include-boring');

    // Normalize and build signature of source (ASCII-only)
    $normalize = function (string $s): string {
        $s = strtolower($s);
        // Keep only ASCII letters and digits
        $s = preg_replace('/[^a-z]/i', '', $s) ?? '';
        return $s;
    };
    $makeSignature = function (string $s) use ($normalize): string {
        $norm = $normalize($s);
        $chars = str_split($norm);
        sort($chars);
        return implode('', $chars);
    };

    $srcSig = $makeSignature($sourceName);
    $srcLen = strlen($srcSig);


    // Helper: check if word signature is a multiset-subset of the source signature (ASCII-only)
    $isSubset = function (string $small, string $big): bool {
        // both are sorted ASCII strings
        $i = 0; $j = 0; $ls = strlen($small); $lb = strlen($big);
        if ($ls === 0) return true;
        while ($i < $ls && $j < $lb) {
            $cs = $small[$i];
            $cb = $big[$j];
            if ($cs === $cb) { $i++; $j++; }
            elseif ($cs > $cb) { $j++; }
            else { return false; }
        }
        return $i === $ls;
    };

    // Build base query
    $query = DB::table('words')->select('id','word','token_type','list_type','signature');
    if ($filterToken !== '') $query->where('token_type', $filterToken);
    if ($filterList !== '') {
        $query->where('list_type', $filterList);
    } else {
        // By default exclude boring words unless explicitly included
        if (!$includeBoring) {
            $query->where('list_type', '!=', 'boring');
        }
    }

    // We'll collect matches grouped by token_type then list_type
    $grouped = [];
    $total = 0;

    // Iterate efficiently in chunks by id
    $query->orderBy('id');
    $query->chunkById(1000, function ($rows) use (&$grouped, &$total, $srcSig, $srcLen, $isSubset) {
        foreach ($rows as $r) {
            // quick length reject: word cannot be longer than source
            $len = strlen($r->signature);
            if ($len > $srcLen) continue;
            if (!$isSubset((string)$r->signature, $srcSig)) continue;

            $tok = (string)$r->token_type;
            $lst = (string)$r->list_type;
            $grouped[$tok][$lst][] = [
                'id' => (int)$r->id,
                'word' => (string)$r->word,
                'signature' => (string)$r->signature,
            ];
            $total++;
        }
    }, 'id');

    if ($asJson) {
        $payload = [
            'source' => $sourceName,
            'signature' => $srcSig,
            'total_matches' => $total,
            'groups' => $grouped,
        ];
        $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        return 0;
    }

    $this->info('Source: ' . $sourceName . ' | signature=' . $srcSig . ' | total matches=' . $total);
    if ($total === 0) return 0;

    // Pretty print grouped summary (limit sample per bucket for readability)
    $sampleLimit = 10;
    ksort($grouped, SORT_STRING);
    foreach ($grouped as $token => $byList) {
        $this->line('[' . $token . ']');
        ksort($byList, SORT_STRING);
        foreach ($byList as $listType => $items) {
            $count = count($items);
            $this->line(sprintf('  - %s: %d', $listType, $count));
            $show = array_slice($items, 0, $sampleLimit);
            foreach ($show as $it) {
                $this->line('      • ' . $it['word'] . ' (' . $it['signature'] . ')');
            }
            if ($count > $sampleLimit) {
                $this->line('      ... and ' . ($count - $sampleLimit) . ' more');
            }
        }
    }

    return 0;
})->purpose('Find all token word matches whose letters fit within the given source name');


}

*/
