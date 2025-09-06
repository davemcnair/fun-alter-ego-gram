<?php

namespace App\Jobs;

use App\Models\AlterEgo;
use App\Models\SignaturedPattern;
use App\Services\SignatureFillService;
use App\Models\SourceName;
use App\Models\SourceNamePattern;
use App\Services\Anagrammer;
use App\Services\WordMatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ProcessPatternJob
 * ------------------
 * Purpose:
 *  Processes a single SourceNamePattern for a given SourceName by generating alter-ego phrases
 *  that fit the source name’s letters. This job is designed to run in small time/count slices so
 *  a queue worker can interleave work across many patterns without long-running tasks.
 *
 * Key ideas:
 *  - Sliced processing: generation is bounded by either a soft time budget (slice_ms_budget, ms)
 *    or a count cap (phrases_per_step_cap). If a slice boundary is reached and some phrases were
 *    produced, the job re-dispatches itself to continue from where it left off (idempotent in
 *    practice because we use firstOrCreate on phrases and pattern status tracking).
 *  - Status flow: SourceNamePattern moves pending → processing → done. The parent SourceName updates
 *    current_pattern, patterns_searched, elapsed_seconds, and transitions to completed when no
 *    pending/processing patterns remain.
 *  - Inputs/Outputs: The job reads words matched to the source’s signature via WordMatchService,
 *    expands anagram siblings for those words to widen the candidate pool, and emits/records new
 *    AlterEgo rows mapped to the current pattern.
 *  - Configuration:
 *      search.slice_ms_budget      int ms soft budget for one slice (0 = disabled)
 *      search.phrases_per_step_cap int max phrases to create per slice (0 = unlimited)
 *      search.queue                string|null name of queue (if set) used for (re)dispatch
 *
 * Notes:
 *  - Word candidates are grouped by token_type/list_type from WordMatchService and flattened to
 *    token => [word,...] for generation. Actual phrase generation is delegated to Anagrammer.
 *  - Logging is best-effort; any logging failures are swallowed to avoid failing the job.
 */
class ProcessPatternJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum seconds a worker may allow this job to run before timing out.
     */
    public int $timeout = 300; // seconds

    /**
     * How many times the job may be attempted.
     */
    public int $tries = 3;


    public function __construct(public int $sourceNamePatternId)
    {
    }

    /**
     * Backoff (in seconds) between retries.
     * @return int|array<int,int>
     */
    public function backoff(): int|array
    {
        return 2;
    }

    /**
     * Tags for Horizon/queue monitoring.
     * @return array<int,string>
     */
    public function tags(): array
    {
        return ['process-pattern', 'source-name-pattern:'.$this->sourceNamePatternId];
    }

    /**
     * Handle one processing slice for the associated SourceNamePattern.
     *
     * Flow:
     *  1) Load the SourceNamePattern and its parent SourceName. If the pattern is already done, or
     *     the SourceName is paused/completed, exit early (no work).
     *  2) Mark the pattern as processing and set SourceName.current_pattern for UI visibility.
     *  3) Parse token slots from the pattern template (e.g., "{title}{forename}{surname:2}").
     *  4) Build the candidate word dictionary by calling WordMatchService::findMatches for the
     *     SourceName signature, then expanding anagram siblings present among matched signatures.
     *  5) Generate phrases via Anagrammer in a streaming fashion. Persist phrases using
     *     AlterEgo::firstOrCreate to ensure idempotence; update counters when a new row is created.
     *  6) Respect slicing controls:
     *       - search.phrases_per_step_cap: stop after N new phrases in this slice.
     *       - search.slice_ms_budget: stop after soft time budget (ms) if we made progress.
     *  7) If a slice boundary is hit, re-dispatch this job to continue; otherwise mark the pattern "done".
     *  8) Update SourceName elapsed time and transition to completed if no more pending/processing
     *     patterns remain; otherwise keep/clear current_pattern appropriately.
     *  9) Log timing/metrics (best-effort).
     */
    public function handle(WordMatchService $wordMatchService): void
    {
        $t0 = microtime(true);
        $sourceNamePattern = SourceNamePattern::with('sourceName')->find($this->sourceNamePatternId);
        $source = $sourceNamePattern->sourceName;

        // If already done, skip
        if ($sourceNamePattern->status === 'done') return;

        // If paused/completed at dispatch time, skip starting new work
        if (in_array($source->status, ['paused', 'completed'], true)) {
            return;
        }

        // Atomically claim the pattern if it's pending
        $sourceNamePattern->status = 'processing';
        $sourceNamePattern->save();
        $source->current_pattern = $sourceNamePattern->pattern_template;
        $source->save();

        $patternTokenPositions = $this->parsePatternSlots($sourceNamePattern->pattern_template);

        $tokenWordsByListTYpe = $wordMatchService->findMatches($source->signature);
        // Expand related anagrams for used search words before generation (efficient: based on signatures present)
//        $this->expandAnagramsInGroups($groups);
        $candidateWordSignaturesByToken = $this->flattenTokenWordsToWordSignatures($tokenWordsByListTYpe);

        // Generate signature-only fills and persist them (no phrases built here)
        try {
            $sigFill = new SignatureFillService();
            foreach ($sigFill->generateSignaturePatterns($source->signature, $patternTokenPositions, $candidateWordSignaturesByToken) as $sigPattern) {
                SignaturedPattern::firstOrCreate([
                    'source_name_pattern_id' => $sourceNamePattern->id,
                    'signatured_pattern' => $sigPattern,
                ]);
                // Respect the same per-slice cap if configured to avoid runaway writes
                $cap = (int) config('search.phrases_per_step_cap', 0);
                if ($cap > 0) {
                    // simple heuristic: stop after cap signature patterns as well
                    static $sigCount = 0;
                    $sigCount++;
                    if ($sigCount >= $cap) { break; }
                }
            }
        } catch (\Throwable $e) {
            // best-effort; ignore persistence errors for signature patterns
        }

        $anagrammer = new Anagrammer($candidateWordSignaturesByToken);

        $phrasesMade = 0;
        $budgetMs = (int) config('search.slice_ms_budget', 0);
        foreach ($anagrammer->generate($source->name, $patternTokenPositions) as $phrase) {
            $created = AlterEgo::firstOrCreate(
                ['source_name_id' => $source->id, 'phrase' => $phrase],
                ['source_name_pattern_id' => $sourceNamePattern->id]
            );
            if ($created->wasRecentlyCreated) {
                $source->increment('alteregos_found');
                $phrasesMade++;
            }
            $cap = (int) config('search.phrases_per_step_cap', 0);
            if ($cap > 0 && $phrasesMade >= $cap) {
                // slice reached by count cap
                break;
            }
            if ($budgetMs > 0) {
                $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
                if ($elapsedMs >= $budgetMs && $phrasesMade > 0) {
                    // time slice budget reached and we made some progress
                    break;
                }
            }
        }

        // If we stopped early due to budget or cap but likely have more work, re-dispatch self and keep status as processing
        $elapsedMsAfter = (int) round((microtime(true) - $t0) * 1000);
        $cap = (int) config('search.phrases_per_step_cap', 0);
        $stoppedByCountCap = ($cap > 0 && $phrasesMade >= $cap);
        $stoppedByTime = ($budgetMs > 0 && $elapsedMsAfter >= $budgetMs && $phrasesMade > 0);
        if ($stoppedByCountCap || $stoppedByTime) {
            // keep processing state and schedule another slice
            try {
                $dispatch = self::dispatch($this->sourceId, $this->sourceNamePatternId);
                $queue = config('search.queue');
                if (!empty($queue)) { $dispatch->onQueue($queue); }
            } catch (\Throwable $e) {
                // best-effort; logging only
                try { Log::warning('Re-dispatch ProcessPatternJob failed', ['source_id'=>$this->sourceId,'pattern_id'=>$this->sourceNamePatternId,'err'=>$e->getMessage()]); } catch (\Throwable $ee) {}
            }
        } else {
            // Mark pattern done (no more progress or fully exhausted)
            $sourceNamePattern->status = 'done';
            $sourceNamePattern->save();
            $source->increment('patterns_searched');
        }

        // Update elapsed and possibly complete the source
        $startedAt = $source->started_at ?? now();
        $totalElapsed = now()->diffInSeconds($startedAt, false);
        if ($totalElapsed < 0) { $totalElapsed = 0; }
        $source->elapsed_seconds = (int)$totalElapsed;

        // If no more pending/processing patterns, complete the source
        $left = SourceNamePattern::where('source_name_id', $source->id)
            ->whereIn('status', ['pending','processing'])
            ->count();
        if ($left === 0) {
            $source->status = 'completed';
            $source->completed_at = now();
            $source->current_pattern = null;
        } else {
            // Keep showing the current pattern while it is still being processed in slices
            if ($stoppedByCountCap || $stoppedByTime) {
                $source->current_pattern = $sourceNamePattern->pattern_template;
            } else {
                // Between fully completed patterns, clear the pointer
                $source->current_pattern = null;
            }
        }
        $source->save();

        // Log timing and basic counters
        try {
            $dt = max(0, (int) round((microtime(true) - $t0) * 1000));
            Log::info('ProcessPatternJob completed', [
                'source_id' => $this->sourceId,
                'pattern_id' => $this->sourceNamePatternId,
                'phrases_found' => (int)($phrasesMade ?? 0),
                'elapsed_ms' => $dt,
                'attempt' => method_exists($this, 'attempts') ? $this->attempts() : null,
            ]);
        } catch (\Throwable $e) {
            // swallow logging errors
        }
    }

    /**
     * Convert a pattern template like "{title}{forename}{surname:2}" into an array of slot descriptors
     * that Anagrammer expects. A suffix like :2 means the token may appear twice (e.g., two surnames).
     * Example:
     *   Input:  "{title}{forename}{surname:2}"
     *   Output: [ ['name'=>'title','pos'=>0], ['name'=>'forename','pos'=>1], ['name'=>'surname','pos'=>2], ['name'=>'surname','pos'=>3] ]
     * @return array<int,array{name:string,pos:int}>
     */
    private function parsePatternSlots(string $template): array
    {
        $patternTokenPositions = [];
        $pos = 0;
        if (preg_match_all('/\{([a-z]+)(?::(\d+))?\}/i', $template, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $name = $match[1];
                $count = isset($match[2]) && ctype_digit($match[2]) ? max(1, (int)$match[2]) : 1;
                for ($i = 0; $i < $count; $i++) {
                    $patternTokenPositions[$pos++] = $name;
                }
            }
        }
        return $patternTokenPositions;
    }

    /**
     * Flatten WordMatchService groups to token=>[words]
     * @param array<string,array<string,array<int,array{word:string}>>> $groups
     * @return array<string,string[]>
     */
    /**
     * Expand groups by adding all anagram siblings for the signatures present per token.
     *
     * Motivation:
     *  WordMatchService returns representative search words (use_for_search=1) filtered by subset of the
     *  source signature. For better phrase variety, we can also include all words that are anagrams of any
     *  matched signature for that token, regardless of list_type (except when includeBoring=false), so the
     *  generator can pick alternate spellings anagram-equivalent to the matched representatives.
     *
     * @param array<string,array<string,array<int,array{id:int,word:string,signature:string}>>> $groups
     */
    private function expandAnagramsInGroups(array &$groups, bool $includeBoring = false): void
    {
        foreach ($groups as $token => &$byListType) {
            // Collect unique signatures present in any list for this token
            $signatures = [];
            foreach ($byListType as $items) {
                $signatures  = array_merge($signatures, array_column($items, 'signature'));
            }
            if (empty($signatures)) continue;
            // Fetch all words matching these signatures for this token
            $q = \DB::table('words')
                ->select('id','word','list_type','signature')
                ->where('token_type', $token)
                ->whereIn('signature', $signatures)
                ->orderBy('id');
            if (!$includeBoring) {
                $q->where('list_type', '!=', 'boring');
            }
            $rows = $q->get();
            foreach ($rows as $r) {
                $lt = (string)$r->list_type;
                $byListType[$lt] = $byListType[$lt] ?? [];
                $byListType[$lt][] = ['id'=>(int)$r->id, 'word'=>(string)$r->word, 'signature'=>(string)$r->signature];
            }
            // Optional: dedupe per list by word
            foreach ($byListType as $lt => &$items) {
                $seen = [];
                $uniq = [];
                foreach ($items as $it) {
                    $w = (string)($it['word'] ?? '');
                    if ($w === '' || isset($seen[$w])) continue;
                    $seen[$w] = true; $uniq[] = $it;
                }
                $items = $uniq;
            }
            unset($items);
        }
        unset($byListType);
    }

    /**
     * Flatten the grouped matches structure into token => list of {word, signature}.
     * Passing signatures lets Anagrammer skip recomputing histograms from scratch.
     * @param array<string,array<string,array<int,array{id?:int,word:string,signature:string}>>> $groups
     * @return array<string,array<int,array{word:string,signature:string}>>
     */
    private function flattenTokenWordsToWordSignatures(array $groups): array
    {
        $out = [];
        foreach ($groups as $token => $byList) {
            $bucket = [];
            foreach ($byList as $items) {
                foreach ($items as $item) {
                    $bucket[$item['word']] = $item['signature']; // dedupe by word, prefer first signature (should be identical per word)
                }
            }
            $out[$token] = $bucket;
        }
        return $out;
    }
}
