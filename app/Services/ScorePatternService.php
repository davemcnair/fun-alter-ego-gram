<?php

namespace App\Services;

final class ScorePatternService
{
    /**
     * Compute the score for a pattern meta array and its template.
     * Lower scores sort earlier (higher priority).
     *
     * @param array{title:int,fn:int,ini:int,pre:int,sn:int,suf:int,hon:int} $p
     */
    public function score(array $p, string $tpl): int
    {
        $segments = ($p['title']>0) + ($p['fn']>0 ? $p['fn'] : 0) + ($p['ini']>0) + ($p['pre']>0) + ($p['sn']>0 ? $p['sn'] : 0) + ($p['suf']>0) + ($p['hon']>0);
        $score = 0;
        $score += $segments * 10;
        if ($p['title']>0) $score += 3;
        if ($p['ini']>0)   $score += 20; // rarer
        if ($p['pre']>0)   $score += 8;
        if ($p['suf']>0)   $score += 5;
        if ($p['hon']>0)   $score += 9;
        // Penalize multiple forenames more strongly
        if ($p['fn'] === 2) { $score += 40; }
        elseif ($p['fn'] > 2) { $score += 40 + ($p['fn'] - 2) * 8; }
        // Keep a small incremental penalty for any additional beyond 1 as a baseline
        if ($p['fn']>1) $score += ($p['fn']-1) * 2;
        // Slightly increase penalty for many surnames (3+)
        if ($p['sn']>1) $score += ($p['sn']-1) * 2;
        if ($p['sn'] >= 3) {
            $score += match($p['sn']) { 3 => 35, 4 => 55, default => 75 };
        }
        if ($p['fn']===0) $score += 30;
        // Special boosts for top templates
        if ($tpl === '{forename}{surname}') return -1000;
        if ($tpl === '{forename}{surname:2}') return -999;
        if ($tpl === '{title}{forename}{surname}') return -998;
        return $score;
    }
}
