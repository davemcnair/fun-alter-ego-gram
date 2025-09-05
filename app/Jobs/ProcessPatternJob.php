<?php

namespace App\Jobs;

use App\Models\AlterEgo;
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

        $slots = $this->parsePatternSlots($sourceNamePattern->pattern_template);

        $matchesPayload = $wordMatchService->findMatches($source->signature);
        // Expand related anagrams for used search words before generation (efficient: based on signatures present)
        $groups = is_array($matchesPayload) ? $matchesPayload : [];
        $this->expandAnagramsInGroups($groups);
        $candidatesByToken = $this->flattenMatchesByToken($groups);

        $anagrammer = new Anagrammer($candidatesByToken);

        $phrasesMade = 0;
        $budgetMs = (int) config('search.slice_ms_budget', 0);
        foreach ($anagrammer->generate($source->name, $slots) as $phrase) {
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
     * Convert a template like "{title}{forename}{surname:2}" into token slots array.
     * @return array<int,array{name:string,pos:int}>
     */
    private function parsePatternSlots(string $template): array
    {
        $slots = [];
        $pos = 0;
        if (preg_match_all('/\{([a-z]+)(?::(\d+))?\}/i', $template, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $name = strtolower($match[1]);
                $count = isset($match[2]) && ctype_digit($match[2]) ? max(1, (int)$match[2]) : 1;
                for ($i = 0; $i < $count; $i++) {
                    $slots[] = ['name' => $name, 'pos' => $pos++];
                }
            }
        }
        return $slots;
    }

    /**
     * Flatten WordMatchService groups to token=>[words]
     * @param array<string,array<string,array<int,array{word:string}>>> $groups
     * @return array<string,string[]>
     */
    /**
     * Expand groups by adding all anagram siblings for the signatures present per token.
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

    private function flattenMatchesByToken(array $groups): array
    {
        $out = [];
        foreach ($groups as $token => $byList) {
            $bucket = [];
            foreach ($byList as $listType => $items) {
                foreach ($items as $it) {
                    $w = (string)($it['word'] ?? '');
                    if ($w !== '') $bucket[$w] = true; // dedupe
                }
            }
            $out[$token] = array_keys($bucket);
        }
        return $out;
    }
}
