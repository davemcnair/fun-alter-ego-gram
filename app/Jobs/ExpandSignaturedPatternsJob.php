<?php

namespace App\Jobs;

use App\Models\SourceName;
use App\Models\Word;
use App\Models\AlterEgo;
use App\Services\PhraseBuilderService;
use App\Models\SourceNamePattern;
use App\Services\WordMatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
class ExpandSignaturedPatternsJob implements ShouldQueue
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
        return ['expand-signatured-patterns', 'source-name-pattern:'.$this->sourceNamePatternId];
    }

    /**
     * Handle filling pattern signatures for the associated SourceNamePattern.
     */
    public function handle(
        WordMatchService $wordMatchService,
        PhraseBuilderService $phraseBuilderService
    ): void
    {
        // Load SNP with parent SourceName and signatured patterns
        $sourceNamePattern = SourceNamePattern::with(['sourceName','signaturedPatterns'])
            ->find($this->sourceNamePatternId);
        if (!$sourceNamePattern) return;
        /** @var SourceName $source */
        $source = $sourceNamePattern->sourceName;

        // If already done, skip
        if ($sourceNamePattern->status === 'done') return;

        // Claim the pattern for expansion (reuse existing allowed status)
        $sourceNamePattern->status = 'processing';
        $sourceNamePattern->save();

        // Precompute a deterministic word picker for (token, signature)
        $wordCache = [];
        $pick = function(string $token, string $signature) use (&$wordCache) : ?string {
            $key = strtolower($token).'|'.strtolower($signature);
            if (array_key_exists($key, $wordCache)) return $wordCache[$key];
            // Deterministic preference: fun > ok > boring; then alphabetically
            $rows = Word::query()
                ->where('token_type', $token)
                ->where('signature', $signature)
                ->orderByRaw("CASE list_type WHEN 'fun' THEN 1 WHEN 'ok' THEN 2 ELSE 3 END")
                ->orderBy('word')
                ->limit(1)
                ->get(['word']);
            $chosen = $rows->first()->word ?? null;
            $wordCache[$key] = $chosen ? (string)$chosen : null;
            return $wordCache[$key];
        };

        // Build slot order from the pattern template for formatting
        $slotOrder = $this->buildSlotOrderFromTemplate((string)$sourceNamePattern->pattern_template);

        $createdCount = 0;
        foreach ($sourceNamePattern->signaturedPatterns as $sigRow) {
            $pairs = $this->parseSignaturedPattern((string)$sigRow->signatured_pattern);
            if (empty($pairs)) continue;

            // Resolve words deterministically for each slot
            $words = [];
            $ok = true;
            foreach ($pairs as $idx => $pair) {
                $tok = (string)($pair['token'] ?? '');
                $sig = (string)($pair['signature'] ?? '');
                if ($tok === '' || $sig === '') { $ok = false; break; }
                $w = $pick($tok, $sig);
                if ($w === null || $w === '') { $ok = false; break; }
                $words[] = $w;
            }
            if (!$ok) continue;

            // Format phrase with proper surname hyphenation/casing
            try {
                $phrase = $phraseBuilderService->formatPhraseBySlots($words, $slotOrder, false);
            } catch (\Throwable $e) {
                // Fallback: simple join
                $phrase = trim(implode(' ', array_filter($words, fn($w) => $w !== '')));
            }
            if ($phrase === '') continue;

            // Persist as AlterEgo (idempotent)
            AlterEgo::firstOrCreate(
                ['source_name_id' => $source->id, 'phrase' => $phrase],
                ['source_name_pattern_id' => $sourceNamePattern->id]
            );
            $createdCount++;
        }

        // Mark as done after expansion (Stage 1 minimal state update)
        $sourceNamePattern->status = 'done';
        $sourceNamePattern->save();

        // Optional log
        try { Log::info('Expanded signatured patterns for SNP '.$sourceNamePattern->id.' => '.$createdCount.' phrase(s).'); } catch (\Throwable $e) {}
    }

    /**
     * Parse a signaturedPattern string like "{forename:aadm}{surname:ciinv}" into an ordered list of
     * [ ['token'=>'forename','signature'=>'aadm'], ... ]
     * @return array<int,array{token:string,signature:string}>
     */
    private function parseSignaturedPattern(string $s): array
    {
        $out = [];
        if (preg_match_all('/\{([a-z]+):([a-z]+)\}/i', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $out[] = [ 'token' => strtolower($match[1]), 'signature' => strtolower($match[2]) ];
            }
        }
        return $out;
    }

    /**
     * Build a slot order array from a pattern template, suitable for PhraseBuilderService.
     * Example input: "{title}{forename}{surname:2}" ->
     *   [ ['name'=>'title','pos'=>0], ['name'=>'forename','pos'=>1], ['name'=>'surname','pos'=>2], ['name'=>'surname','pos'=>3] ]
     * @return array<int,array{name:string,pos:int}>
     */
    private function buildSlotOrderFromTemplate(string $template): array
    {
        $slotOrder = [];
        $pos = 0;
        if (preg_match_all('/\{([a-z]+)(?::(\d+))?\}/i', $template, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $name = strtolower($match[1]);
                $count = isset($match[2]) && ctype_digit($match[2]) ? max(1, (int)$match[2]) : 1;
                for ($i = 0; $i < $count; $i++) {
                    $slotOrder[] = ['name' => $name, 'pos' => $pos++];
                }
            }
        }
        return $slotOrder;
    }
}
