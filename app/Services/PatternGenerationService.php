<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PatternGenerationService
{
    public function __construct(private readonly TextSignatureService $sig)
    {
    }

    /**
     * Generate patterns and optionally persist them.
     * Returns an array with 'list' (scored list), and 'stored' count if persisted.
     *
     * @param bool $dryRun
     * @param int $printN
     * @return array{list: array<int, array{template:string, score:int}>, stored?:int}
     */
    public function generate(bool $dryRun = false, int $printN = 0): array
    {
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

        if (!$exists('surname')) {
            throw new \RuntimeException('Missing required token: surname');
        }

        $maxForename = $getMax('forename', 2);
        $maxSurname  = $getMax('surname', 5);
        $hasTitle     = $exists('title');
        $hasInitials  = $exists('initials');
        $hasPrefix    = $exists('prefix');
        $hasSuffix    = $exists('suffix');
        $hasHonorific = $exists('honorific');

        $patterns = [];
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

        for ($sn = 1; $sn <= $maxSurname; $sn++) {
            for ($fn = 0; $fn <= $maxForename; $fn++) {
                for ($title = 0; $title <= ($hasTitle ? 1 : 0); $title++) {
                    for ($ini = 0; $ini <= ($hasInitials ? 1 : 0); $ini++) {
                        for ($pre = 0; $pre <= ($hasPrefix ? 1 : 0); $pre++) {
                            for ($suf = 0; $suf <= ($hasSuffix ? 1 : 0); $suf++) {
                                for ($hon = 0; $hon <= ($hasHonorific ? 1 : 0); $hon++) {
                                    if ($pre > 0 && $sn === 0) continue;
                                    if ($suf > 0 && $sn === 0) continue;
                                    if ($fn >= 2 && $ini > 0) continue;
                                    // new rules
                                    if ($sn > 0 && ($title === 0 && $fn === 0 && $ini === 0)) continue;
                                    if ($fn > 0 && ($title === 0 && $sn === 0 && $hon === 0)) continue;
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

        $scoreOf = function (string $tpl, array $p) {
            $segments = ($p['title']>0) + ($p['fn']>0 ? $p['fn'] : 0) + ($p['ini']>0) + ($p['pre']>0) + ($p['sn']>0 ? $p['sn'] : 0) + ($p['suf']>0) + ($p['hon']>0);
            $score = 0;
            $score += $segments * 10;
            if ($p['title']>0) $score += 3;
            if ($p['ini']>0)   $score += 20; // rarer
            if ($p['pre']>0)   $score += 8;
            if ($p['suf']>0)   $score += 5;
            if ($p['hon']>0)   $score += 9;
            if ($p['fn']>1) $score += ($p['fn']-1) * 2;
            if ($p['sn']>1) $score += ($p['sn']-1) * 2;
            if ($p['sn'] >= 3) {
                $score += match($p['sn']) { 3 => 25, 4 => 45, default => 65 };
            }
            if ($p['fn']===0) $score += 30;
            if ($tpl === '{forename}{surname}') return -1000;
            if ($tpl === '{forename}{surname:2}') return -999;
            if ($tpl === '{title}{forename}{surname}') return -998;
            return $score;
        };

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
                $la = strlen($a['template']);
                $lb = strlen($b['template']);
                if ($la === $lb) return strcmp($a['template'], $b['template']);
                return $la <=> $lb;
            }
            return $a['score'] <=> $b['score'];
        });

        $result = ['list' => array_map(fn($r) => ['template' => $r['template'], 'score' => $r['score']], $list)];

        if (!$dryRun) {
            try { DB::table('patterns')->delete(); } catch (\Throwable $e) {}
            $rank = 1; $now = now(); $batch = [];
            $minLenTitle     = $getMinLen('title', 0);
            $minLenForename  = $getMinLen('forename', 0);
            $minLenInitials  = $getMinLen('initials', 0);
            $minLenPrefix    = $getMinLen('prefix', 0);
            $minLenSurname   = $getMinLen('surname', 0);
            $minLenSuffix    = $getMinLen('suffix', 0);
            $minLenHonorific = $getMinLen('honorific', 0);
            foreach ($list as $row) {
                $p = $row['meta'];
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
                if (count($batch) >= 500) { DB::table('patterns')->insert($batch); $batch = []; }
            }
            if (!empty($batch)) DB::table('patterns')->insert($batch);
            $result['stored'] = $rank - 1;
        }

        return $result;
    }
}
