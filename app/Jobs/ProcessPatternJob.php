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

class ProcessPatternJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $sourceId;
    public int $patternId;
    public int $timeout = 300; // seconds

    public function __construct(int $sourceId, int $patternId)
    {
        $this->sourceId = $sourceId;
        $this->patternId = $patternId;
    }

    public function handle(WordMatchService $wordMatchService): void
    {
        $pattern = SourceNamePattern::find($this->patternId);
        if (!$pattern || (int)$pattern->source_name_id !== (int)$this->sourceId) {
            return; // nothing to do
        }
        $source = SourceName::find($this->sourceId);
        if (!$source) return;

        // If already done, skip
        if ($pattern->status === 'done') return;

        // If paused/completed at dispatch time, skip starting new work
        if (in_array($source->status, ['paused', 'completed'], true)) {
            return;
        }

        // Atomically claim the pattern if it's pending
        $updated = DB::table('source_name_patterns')
            ->where('id', $pattern->id)
            ->where('status', 'pending')
            ->update(['status' => 'processing', 'updated_at' => now()]);
        if ($pattern->status === 'processing' || $updated > 0) {
            // Claimed or already processing: proceed
        } else {
            // Another worker may have handled it already or it's not pending; skip
            return;
        }

        $source->current_pattern = $pattern->pattern_template;
        $source->save();

        $slots = $this->parsePatternSlots($pattern->pattern_template);

        // Include boring lists during generation to allow complete phrases
        $matchesPayload = $wordMatchService->findMatches($source->signature, [
            'include_boring' => true,
        ]);
        // Expand related anagrams for used search words before generation (efficient: based on signatures present)
        $groups = $matchesPayload['groups'] ?? [];
        $this->expandAnagramsInGroups($groups, includeBoring: true);
        $candidatesByToken = $this->flattenMatchesByToken($groups);

        $anagrammer = new Anagrammer($candidatesByToken);

        $phrasesMade = 0;
        foreach ($anagrammer->generate($source->name, $slots) as $phrase) {
            $created = AlterEgo::firstOrCreate(
                ['source_name_id' => $source->id, 'phrase' => $phrase],
                ['source_name_pattern_id' => $pattern->id]
            );
            if ($created->wasRecentlyCreated) {
                $source->increment('alteregos_found');
                $phrasesMade++;
            }
            $cap = (int) config('search.phrases_per_step_cap', 0);
            if ($cap > 0 && $phrasesMade >= $cap) {
                break;
            }
        }

        // Mark pattern done
        $pattern->status = 'done';
        $pattern->save();
        $source->increment('patterns_searched');

        // Update elapsed and possibly complete the source
        $startedAt = $source->started_at ?? now();
        $totalElapsed = now()->diffInSeconds($startedAt, false);
        if ($totalElapsed < 0) { $totalElapsed = 0; }
        $source->elapsed_seconds = (int)$totalElapsed;

        // If no more pending patterns, complete the source
        $pendingLeft = SourceNamePattern::where('source_name_id', $source->id)
            ->where('status', 'pending')
            ->count();
        if ($pendingLeft === 0) {
            $source->status = 'completed';
            $source->completed_at = now();
            $source->current_pattern = null;
        } else {
            $source->current_pattern = null; // clear current pointer between patterns
        }
        $source->save();
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
    private function expandAnagramsInGroups(array &$groups, bool $includeBoring = true): void
    {
        foreach ($groups as $token => &$byList) {
            // Collect unique signatures present in any list for this token
            $sigs = [];
            foreach ($byList as $listType => $items) {
                foreach ($items as $it) {
                    $sig = (string)($it['signature'] ?? '');
                    if ($sig !== '') $sigs[$sig] = true;
                }
            }
            $sigList = array_keys($sigs);
            if (empty($sigList)) continue;
            // Fetch all words matching these signatures for this token
            $q = \DB::table('words')
                ->select('id','word','list_type','signature')
                ->where('token_type', $token)
                ->whereIn('signature', $sigList)
                ->orderBy('id');
            if (!$includeBoring) {
                $q->where('list_type', '!=', 'boring');
            }
            $rows = $q->get();
            foreach ($rows as $r) {
                $lt = (string)$r->list_type;
                $byList[$lt] = $byList[$lt] ?? [];
                $byList[$lt][] = ['id'=>(int)$r->id, 'word'=>(string)$r->word, 'signature'=>(string)$r->signature];
            }
            // Optional: dedupe per list by word
            foreach ($byList as $lt => &$items) {
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
        unset($byList);
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
