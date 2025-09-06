<?php

namespace Tests\Unit;

use App\Services\DfsService;
use App\Traits\HelpsMatchWords;
use PHPUnit\Framework\TestCase;

class DfsServiceTest extends TestCase
{
    use HelpsMatchWords;

    private function buildCandidates(array $map): array
    {
        // Build the structure DfsService expects per token
        // [token] => [
        //   'signatures' => [ ['signature'=>..., 'len'=>..., 'hist'=>...], ...],
        //   'letterIndices' => [ letter => [indices...] ],
        //   'maxLetterCounts' => [ letter => maxCount ]
        // ]
        $out = [];
        foreach ($map as $token => $sigs) {
            $signatures = [];
            $letterIdx = [];
            $maxes = [];
            foreach (array_values($sigs) as $i => $sig) {
                $sig = preg_replace('/[^a-z]/', '', strtolower($sig));
                $hist = [];
                $len = strlen($sig);
                for ($k=0; $k<$len; $k++) {
                    $ch = $sig[$k];
                    $hist[$ch] = ($hist[$ch] ?? 0) + 1;
                }
                foreach ($hist as $ch => $n) {
                    $letterIdx[$ch] = $letterIdx[$ch] ?? [];
                    $letterIdx[$ch][] = $i;
                    $maxes[$ch] = max($maxes[$ch] ?? 0, $n);
                }
                $signatures[] = ['signature' => $sig, 'len' => $len, 'hist' => $hist];
            }
            // deterministic sort: by len then signature
            usort($signatures, function($a, $b){ if ($a['len'] === $b['len']) return $a['signature'] <=> $b['signature']; return $a['len'] <=> $b['len']; });
            $out[$token] = [
                'signatures' => $signatures,
                'letterIndices' => $letterIdx,
                'maxLetterCounts' => $maxes,
            ];
        }
        return $out;
    }

    public function test_dfs_exact_cover_two_slots(): void
    {
        $dfs = new DfsService();
        $slots = [0 => 'forename', 1 => 'surname'];
        $srcSig = 'aadmciinv'; // Adam + Vinci
        $need = $this->letterCountsFromSignature($srcSig);
        $cands = $this->buildCandidates([
            'forename' => ['aadm', 'adn'],
            'surname'  => ['ciinv', 'ary'],
        ]);
        $out = iterator_to_array($dfs->dfs($slots, $need, $cands, [], []), false);
        $this->assertSame(['{forename:aadm}{surname:ciinv}'], $out);
    }

    public function test_dfs_duplicate_token_run_allows_reuse_of_same_candidate(): void
    {
        $dfs = new DfsService();
        $slots = [0 => 'surname', 1 => 'surname'];
        $srcSig = 'ciinvciinv';
        $need = $this->letterCountsFromSignature($srcSig);
        $cands = $this->buildCandidates([
            'surname' => ['ciinv'],
        ]);
        $out = iterator_to_array($dfs->dfs($slots, $need, $cands, [], []), false);
        $this->assertSame(['{surname:ciinv}{surname:ciinv}'], $out);
    }

    public function test_dfs_impossible_yields_nothing(): void
    {
        $dfs = new DfsService();
        $slots = [0 => 'forename'];
        $srcSig = 'abc';
        $need = $this->letterCountsFromSignature($srcSig);
        $cands = $this->buildCandidates([
            'forename' => ['adn'],
        ]);
        $out = iterator_to_array($dfs->dfs($slots, $need, $cands, [], []), false);
        $this->assertSame([], $out);
    }

    public function test_scalability_prunes_quickly_with_letter_index_and_union(): void
    {
        $dfs = new DfsService();
        // Pattern: 4 slots
        $slots = [0=>'t1', 1=>'t2', 2=>'t3', 3=>'t4'];
        // Build source need with some rare letters forcing pruning
        $srcSig = 'aaaaabbbbccddeeffgghhij';
        $need = $this->letterCountsFromSignature($srcSig);
        // Each token gets many candidates, but only a few contain rare letters 'j' or 'i'.
        $candsMap = [];
        foreach (['t1','t2','t3','t4'] as $t) {
            $list = [];
            // 300 filler candidates of length 3-5 without i/j (should be pruned by letter index)
            for ($k=0; $k<300; $k++) {
                $list[] = 'aaab';
            }
            // Add a handful of viable ones that include needed rare letters
            $list[] = 'aij';
            $list[] = 'bbci';
            $list[] = 'ddffg';
            $candsMap[$t] = $list;
        }
        $cands = $this->buildCandidates($candsMap);
        $t0 = microtime(true);
        $out = iterator_to_array($dfs->dfs($slots, $need, $cands, [], []), false);
        $elapsed = microtime(true) - $t0;
        // We don't assert exact combinations, but ensure the search completes quickly (< 1.0s)
        $this->assertLessThan(1.0, $elapsed, 'DFS should prune quickly via letter index and union checks');
        // And ensure we got at least one result (with the constructed viable candidates)
        $this->assertNotEmpty($out);
    }
}
