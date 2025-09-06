<?php

namespace App\Services;

use App\Traits\HelpsMatchWords;

/**
 * Anagrammer
 * ----------
 * Purpose:
 *  Given a source name and a pattern template broken into token slots (e.g., {title}{forename}{surname:2}),
 *  generate phrases whose letters are an anagrammatic subset of the source. Words for each token are provided
 *  up front (e.g., from WordMatchService). Anagrammer streams valid phrases without materializing the full
 *  Cartesian product, using pruning and safe feasibility checks.
 *
 * Inputs:
 *  - matches: array token => [candidate words]. Example: ['title'=>['Dr','Vicar'], 'forename'=>['Adam','Dan'], 'surname'=>['Vinci','McNair']]
 *  - generate(sourceName, patternSlots): sourceName is the raw string; patternSlots is an ordered list of slot descriptors
 *    like [ ['name'=>'title','pos'=>0], ['name'=>'forename','pos'=>1], ['name'=>'surname','pos'=>2], ... ].
 *
 * Key ideas/pruning:
 *  - Precompute per-word histograms of normalized letters and per-slot letter indexes (letter -> candidate indices).
 *  - DFS first: choose words left-to-right by slots, pruning with:
 *      • canCover: candidate’s histogram must not exceed remaining letter need
 *      • unionCanFill: an upper-bound supply test over remaining slots using per-slot max letter counts
 *      • violatesRunOrder: within runs of identical slot names (e.g., surname:2) enforce non-decreasing order to
 *        avoid duplicate permutations of the same multiset (Dan-Vinci vs Vinci-Dan is both generated, but surname-surname
 *        permutations are normalized to avoid duplicate ordering when the tokens are identical)
 *  - Fallback to bruteStream: if DFS yields nothing (extreme cases), enumerate combinations slot-by-slot in a streaming
 *    manner with the same safe checks to keep memory bounded.
 *
 * Output:
 *  - generate(...) yields formatted phrases as strings, already assembled according to PhraseBuilderService rules
 *    (proper hyphenation/capitalization for multiple surnames, etc.).
 *
 * Performance:
      *  - Candidate narrowing by letter index significantly reduces branching.
      *  - unionCanFill uses precomputed per-slot letter maxima to quickly prune impossible branches without scanning all
      *    remaining candidates.
      *  - Emits phrases as a generator, enabling callers to cap counts or time slices.
      *
      * Why precomputing maxLetterCountsPerToken helps:
      *  - For each token type (e.g., forename, surname), we precompute the maximum number of times each letter appears
      *    in any candidate of that token. Example: if surname candidates include {"Vinci"=ciinv, "Ray"=ary}, then for
      *    token "surname" the max map contains: { c:2, i:2, n:1, v:1, a:1, r:1, y:1 }.
      *  - During DFS, given a set of remaining slots and a remaining letter need, we can sum these per-token maxima over
      *    the remaining slots to obtain an upper-bound supply for each letter. If for any letter the required count
      *    exceeds this upper bound, the branch is impossible and can be pruned immediately without iterating candidates.
      *  - This check is safe (it never prunes valid solutions) because it overestimates availability: it assumes we could
      *    pick, for each remaining slot, a candidate that provides the per-letter maximum simultaneously, which is not
      *    always achievable. Therefore, if even this optimistic supply cannot meet the need, no real combination can.
      *  - Complexity benefit: computing the union upper bound is O(S + L) where S is remaining slots and L the number of
      *    needed letters, whereas scanning all candidates per slot could be far larger. This greatly reduces the search
      *    tree, especially with long patterns or large candidate sets.
      */
final class Anagrammer
{
    use HelpsMatchWords;

    /** @var array<string, array<int, array{name:string,len:int,hist:array<string,int>}>> */
    private array $candidates = [];

    /** @var array<string, array<string, array<int>>> letter -> list indices per token type */
    private array $letterIndex = [];

    /** @var array<string, array<string, int>> per slot type: max occurrences of each letter in any candidate */
    private array $slotLetterMax = [];

    private PhraseBuilderService $phraseBuilder;

    /**
     * @param array<string, array<int, string|array{word:string,signature?:string}>> $matchingSignaturesByToken
     *  Candidates per token. Each candidate may be a plain display word (string) or an object
     *  containing the display 'word' and its precomputed sorted-letter 'signature'. When a signature
     *  is provided, Anagrammer will use it to build histograms without re-normalizing the word.
     *  Examples:
     *    ['forename'=>['Adam','Dan'], 'surname'=>['Vinci','McNair']]
     *    ['forename'=>[['word'=>'Adam','signature'=>'aadm'], ['word'=>'Dan','signature'=>'adn']], ...]
     */
    public function __construct(private readonly array $matchingSignaturesByToken)
    {
        $this->phraseBuilder = new PhraseBuilderService();
        $this->precompute();
    }

    /**
     * Generate phrases for a single pattern.
     *
     * Steps:
     *  1) Normalize slots to a stable ['name'=>..., 'pos'=>...] structure (preserves original order for formatting).
     *  2) Quick sanity: if total needed letters in source are less than an approximate minimum (2 per slot), bail.
     *  3) Try a depth-first search with pruning to stream phrases early (usually sufficient and fast).
     *  4) If DFS yields nothing, fall back to a memory-safe enumerator that streams the Cartesian space with
     *     safe pruning (run-order + canCover), avoiding full materialization.
     *
     * @param array<int, string|array{name:string,pos:int}> $patternSlots Ordered token slots for the pattern.
     * @return \Generator<string> yields full phrases formatted in the original slot order.
     */
    public function generate(string $sourceName, array $patternSlots): \Generator
    {
        $need = $this->letterCountsFromSignature($sourceName);

        // Normalize slots to [ ['name'=>..., 'pos'=>...] ] preserving original order
        $patternTokenPositions = [];
        foreach ($patternSlots as $idx => $slot) {
            if (is_array($slot)) {
                $patternTokenPositions[] = ['name' => (string)$slot['name'], 'pos' => (int)$slot['pos']];
            } else {
                $patternTokenPositions[] = ['name' => (string)$slot, 'pos' => $idx];
            }
        }

        // quick length sanity: min token len approx 2
        $minLen = 2 * count($patternTokenPositions);
        if ($this->sum($need) < $minLen) {
            if (false) yield '';
            return;
        }

        // First attempt: DFS with pruning
        $emitted = false;
        foreach ($this->dfs($patternTokenPositions, $need, [], $patternTokenPositions) as $phrase) {
            $emitted = true;
            yield $phrase;
        }
        if ($emitted) return;

        // Fallback: memory-safe brute-force enumeration without materializing the Cartesian product
        // Iterate over full candidate lists per slot, only pruning with safe checks (run-order and canCover)
        yield from $this->bruteStream($patternTokenPositions, $patternTokenPositions, 0, $need, []);
    }

    /**
     * Precompute per-token candidate data structures:
     *  - candidates: list of entries {name, len, hist} with normalized histogram per word.
     *  - letterIndex: maps each letter to candidate indices for quick narrowing by needed letters.
     *  - slotLetterMax: per token, the maximum count of each letter across its candidates (used for upper-bound supply tests).
     */
    private function precompute(): void
    {
        foreach ($this->matchingSignaturesByToken as $token => $signatures) {
            $this->candidates[$token] = [];
            $this->letterIndex[$token] = []; // e.g. 'a' => [0,7,13]
            $this->slotLetterMax[$token] = [];
            foreach ($signatures as $i => $w) {
                if (is_array($w)) {
                    $name = (string)($w['word'] ?? '');
                    $sig = isset($w['signature']) ? (string)$w['signature'] : '';
                } else {
                    $name = (string)$w;
                    $sig = '';
                }
                // Build histogram from signature if provided; otherwise normalize the name
                if ($sig !== '') {
                    $hist = $this->letterCountsFromSignature($sig);
                    $normLen = strlen($sig);
                } else {
                    $norm = $this->norm($name);
                    if ($norm === '') continue; // skip if no letters remain
                    $hist = $this->letterCountsFromSignature($norm);
                    $normLen = strlen($norm);
                }
                $entry = ['name' => $name, 'len' => $normLen, 'hist' => $hist];
                $this->candidates[$token][$i] = $entry;
                foreach ($hist as $ch => $n) {
                    $this->letterIndex[$token][$ch] ??= [];
                    $this->letterIndex[$token][$ch][] = $i;
                    // track per-letter maximum count among candidates for this slot type
                    $prevMax = $this->slotLetterMax[$token][$ch] ?? 0;
                    if ($n > $prevMax) { $this->slotLetterMax[$token][$ch] = $n; }
                }
            }
        }
    }

    /**
     * Depth-first search that builds phrases slot-by-slot and prunes aggressively.
     * Pruning:
     *  - narrowCandidates picks candidates that contain at least one still-needed letter (by letterIndex).
     *  - canCover ensures each candidate can fit within the remaining letter need.
     *  - unionCanFill ensures the upper-bound supply across remaining slots is sufficient to finish.
     *  - violatesRunOrder prevents duplicate permutations inside consecutive identical-token runs.
     * Emits formatted phrases when all slots are filled and no letters remain.
     *
     * @param array<int, array{name:string,pos:int}> $patternTokenPositions
     * @param array<string,int> $sourceLetterCountsUnfilled Remaining multiset of letters from source to be covered.
     * @param array<int,string> $chosenSignatures map: pos => word
     */
    private function dfs(
        array $patternTokenPositions,
        array $sourceLetterCountsUnfilled,
        array $chosenSignatures,
        array $slotOrder): \Generator
    {
        if (empty($patternTokenPositions)) {
            if (empty($sourceLetterCountsUnfilled)) {
                // Emit in original order by position
                ksort($chosenSignatures);
                $words = array_values($chosenSignatures);
                yield $this->phraseBuilder->formatPhraseBySlots($words, $slotOrder);
            }
            return;
        }

        // Fixed slot ordering: process in the original slot order to ensure exhaustive exploration
        $slot = array_shift($patternTokenPositions);

        // Candidate list narrowed by rarest-needed letter for this slot
        $viableIndices = $this->narrowCandidates($slot['name'], $sourceLetterCountsUnfilled);
        if (empty($viableIndices)) return;

        // Prefer shorter words and then alphabetical to surface concise phrases earlier
        usort($viableIndices, function($a, $b) use ($slot) {
            $sa = $this->candidates[$slot['name']][$a] ?? null;
            $sb = $this->candidates[$slot['name']][$b] ?? null;
            if ($sa === null || $sb === null) return 0;
            if ($sa['len'] === $sb['len']) return strcasecmp($sa['name'], $sb['name']);
            return $sa['len'] <=> $sb['len'];
        });

        foreach ($viableIndices as $i) {
            $cand = $this->candidates[$slot['name']][$i];

            // Prune permutations for consecutive identical-token runs: enforce non-decreasing order
            if ($this->violatesRunOrder($slot, $slotOrder, $chosenSignatures, $cand['name'])) {
                continue;
            }

            // Fast can-fit check
            if (!$this->canCover($sourceLetterCountsUnfilled, $cand['hist'])) continue;

            $nextNeed = $this->subtract($sourceLetterCountsUnfilled, $cand['hist']);

            // Cheap union check over remaining slots (prune impossible letter deficits)
            if (!$this->unionCanFill($patternTokenPositions, $nextNeed)) continue;

            $nextChosen = $chosenSignatures;
            $nextChosen[$slot['pos']] = $cand['name'];
            yield from $this->dfs($patternTokenPositions, $nextNeed, $nextChosen, $slotOrder);
        }
    }

    /**
     * Fallback enumerator: stream all combinations using full candidate lists per slot.
     * Only prunes with safe checks (run-order within identical-token runs and canCover on remaining need).
     * @param array<int, array{name:string,pos:int}> $patternTokenPositions
     * @param array<int, array{name:string,pos:int}> $slotOrder
     * @param int $idx
     * @param array<string,int> $need
     * @param array<int,string> $chosen map: pos => word
     */
    private function bruteStream(array $patternTokenPositions, array $slotOrder, int $idx, array $need, array $chosen): \Generator
    {
        $n = count($patternTokenPositions);
        if ($idx >= $n) {
            if (empty($need)) {
                ksort($chosen);
                $words = array_values($chosen);
                yield $this->phraseBuilder->formatPhraseBySlots($words, $slotOrder);
            }
            return;
        }
        $slot = $patternTokenPositions[$idx];
        $list = $this->candidates[$slot['name']] ?? [];
        if (empty($list)) return;
        foreach ($list as $cand) {
            // Enforce non-decreasing order within runs of identical slot names
            if ($this->violatesRunOrder($slot, $slotOrder, $chosen, (string)$cand['name'])) continue;
            // Quick feasibility: candidate must fit within remaining need
            if (!$this->canCover($need, $cand['hist'])) continue;
            $nextNeed = $this->subtract($need, $cand['hist']);
            $nextChosen = $chosen;
            $nextChosen[$slot['pos']] = (string)$cand['name'];
            yield from $this->bruteStream($patternTokenPositions, $slotOrder, $idx + 1, $nextNeed, $nextChosen);
        }
    }

    /**
     * Pick the slot with fewest viable candidates (most constrained).
     * Note: Currently not used in the DFS which proceeds left-to-right to keep formatting stable and avoid
     * reordering complexities. Kept here for potential future heuristics if we decide to re-order slots.
     */
    private function pickMostConstrainedSlot(array $patternTokenPositions, array $need): int
    {
        $bestIdx = 0; $bestCount = PHP_INT_MAX;
        foreach ($patternTokenPositions as $idx => $slot) {
            $count = count($this->narrowCandidates($slot['name'], $need, cap: 1000)); // cap for speed probing
            if ($count < $bestCount) { $bestCount = $count; $bestIdx = $idx; }
            if ($bestCount === 0) break;
        }
        return $bestIdx;
    }

    /**
     * Narrow candidates by the rarest-needed letter present in this slot type.
     * Falls back to all candidates if no needed letters intersect this slot's index.
     * @return int[] candidate indices for $this->candidates[$slot]
     */
    private function narrowCandidates(string $slot, array $need, int $cap = PHP_INT_MAX): array
    {
        $all = $this->candidates[$slot] ?? [];
        if (empty($all) || empty($need)) return array_keys($all);
        $idxByLetter = $this->letterIndex[$slot] ?? [];
        $union = [];
        // Union indices for letters still needed
        foreach ($need as $ch => $n) {
            if (isset($idxByLetter[$ch])) {
                foreach ($idxByLetter[$ch] as $i) { $union[$i] = true; }
            }
        }
        if (empty($union)) return [];
        $indices = array_keys($union);
        // Filter by canCover(need, cand.hist)
        $filtered = [];
        foreach ($indices as $i) {
            $cand = $all[$i] ?? null;
            if ($cand === null) continue;
            if ($this->canCover($need, $cand['hist'])) {
                $filtered[] = $i;
                if (count($filtered) >= $cap) break;
            }
        }
        return $filtered;
    }

    /** Quick feasibility: do remaining slots’ maximal supplies cover all needed letters? (safe upper bound) */
    private function unionCanFill(array $patternTokenPositions, array $need): bool
    {
        if (empty($need) || empty($patternTokenPositions)) return true;
        // Build an upper-bound supply histogram by summing per-slot-type maxima over the remaining slots
        $supply = [];
        foreach ($patternTokenPositions as $slot) {
            $name = strtolower((string)($slot['name'] ?? ''));
            $maxes = $this->slotLetterMax[$name] ?? [];
            if (empty($maxes)) continue;
            foreach ($need as $ch => $n) {
                if (!isset($maxes[$ch])) continue;
                $supply[$ch] = ($supply[$ch] ?? 0) + (int)$maxes[$ch];
            }
        }
        // If for any needed letter the supply upper bound is less than required, prune
        foreach ($need as $ch => $n) {
            if (($supply[$ch] ?? 0) < $n) return false;
        }
        return true;
    }



    private function sum(array $hist): int
    {
        $t = 0; foreach ($hist as $n) $t += $n; return $t;
    }

    /**
     * Determine if choosing $word for $slot would violate the non-decreasing ordering within a consecutive
     * run of identical token names (e.g., surname followed by surname). This is a symmetry-breaker to avoid
     * generating both permutations of identical-token sequences that only differ by order.
     */
    private function violatesRunOrder(array $slot, array $slotOrder, array $chosen, string $word): bool
    {
        $pos = (int)($slot['pos'] ?? -1);
        $name = strtolower((string)($slot['name'] ?? ''));
        if ($pos <= 0) return false;
        $prev = $slotOrder[$pos - 1] ?? null;
        if (!$prev) return false;
        if (strtolower((string)($prev['name'] ?? '')) !== $name) return false; // not a consecutive run
        $prevWord = $chosen[$pos - 1] ?? null;
        if ($prevWord === null) return false; // previous not chosen yet; but in our left-to-right DFS it should be chosen
        return strcasecmp((string)$prevWord, $word) > 0; // disallow decreasing
    }

    /**
     * Validate that within each run of identical token names (by slotOrder), the sequence of chosen words is
     * non-decreasing lexicographically (case-insensitive). Useful if we ever need to post-validate a phrase.
     */
    private function isNonDecreasingByBlocks(array $words, array $slotOrder): bool
    {
        $n = count($slotOrder);
        $wi = 0;
        for ($i = 0; $i < $n; $i++) {
            $name = strtolower((string)($slotOrder[$i]['name'] ?? ''));
            // determine length of this run
            $j = $i + 1;
            while ($j < $n && strtolower((string)($slotOrder[$j]['name'] ?? '')) === $name) {
                $j++;
            }
            $runLen = $j - $i;
            if ($runLen > 1) {
                $prev = null;
                for ($k = 0; $k < $runLen; $k++) {
                    $w = (string)($words[$wi + $k] ?? '');
                    if ($prev !== null && strcasecmp($prev, $w) > 0) {
                        return false;
                    }
                    $prev = $w;
                }
            }
            $wi += $runLen;
            $i = $j - 1; // advance outer loop to end of run
        }
        return true;
    }
}

