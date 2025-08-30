<?php

namespace App\Http\Controllers;

use App\Models\SourceName;
use App\Models\SourceNamePattern;
use App\Models\AlterEgo;
use App\Services\PatternQueryService;
use App\Services\WordMatchService;
use App\Services\Anagrammer;
use App\Traits\HelpsMatchWords;
use Illuminate\Http\Request;

class SourceNameController extends Controller
{
    use HelpsMatchWords;

    public function index()
    {
        $items = SourceName::withCount('alterEgos')->orderByDesc('id')->paginate(15);
        return view('source_names.index', compact('items'));
    }

    public function create()
    {
        return view('source_names.create');
    }

    public function store(Request $request, PatternQueryService $patterns)
    {
        $data = $request->validate([
            'name' => ['required','string','min:5','max:25'],
            'allow_boring' => ['nullable','boolean'],
        ]);
        $name = trim($data['name']);
        $signature = $this->makeSignature($name);
        if ($signature === '') {
            return back()->withErrors(['name' => 'Please include 5-25 letters in the source name.'])->withInput();
        }

        $includeBoring = (bool)($data['allow_boring'] ?? false);
        $list = $patterns->listForSource($name, $includeBoring);

        $source = SourceName::create([
            'name' => $name,
            'signature' => $signature,
            'status' => 'idle',
            'patterns_total' => (int)($list['meta']['count'] ?? 0),
        ]);

        $selected = array_values(array_unique(array_map('strval', (array)$request->input('templates', []))));
        $sel = array_flip($selected);
        $rows = $list['rows'] ?? [];
        $bulk = [];
        $now = now();
        $maxRank = SourceNamePattern::DEFAULT_MAX_RANK;
        foreach ($rows as $r) {
            $tpl = $r['template'];
            $rank = (int)$r['popularity_rank'];
            $bulk[] = [
                'source_name_id' => $source->id,
                'pattern_template' => $tpl,
                'popularity_rank' => $rank,
                'status' => (empty($selected) ? ($rank <= $maxRank ? 'pending' : 'deselected') : (isset($sel[$tpl]) ? 'pending' : 'deselected')),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if (!empty($bulk)) {
            // Use query builder to bulk insert; if enum/check constraint rejects 'deselected' (older DB),
            // fall back to using 'done' for those rows and retry.
            try {
                \DB::table('source_name_patterns')->insert($bulk);
            } catch (\Illuminate\Database\QueryException $e) {
                $msg = (string) $e->getMessage();
                if (stripos($msg, 'check constraint failed') !== false || stripos($msg, 'constraint failed:') !== false) {
                    $fallback = array_map(function($row){
                        if (($row['status'] ?? '') === 'deselected') { $row['status'] = 'done'; }
                        return $row;
                    }, $bulk);
                    \DB::table('source_name_patterns')->insert($fallback);
                } else {
                    throw $e;
                }
            }
        }
        // Update total to selected count if provided, otherwise to the number initially pending (<=$maxRank)
        if (!empty($selected)) {
            $source->patterns_total = count($selected);
        } else {
            $source->patterns_total = collect($rows)->filter(fn($r) => (int)$r['popularity_rank'] <= $maxRank)->count();
        }
        $source->save();

        return redirect()->route('source-names.show', $source);
    }

    public function show(SourceName $source_name, WordMatchService $wordMatchService)
    {
        // Search page: auto-start via JS on load
        $source = $source_name->fresh();

        // Load patterns and eager-load their alter egos for grouping display
        $patterns = SourceNamePattern::where('source_name_id', $source->id)
            ->orderBy('popularity_rank')
            ->with(['alterEgos' => function($q) use ($source) {
                $q->where('source_name_id', $source->id)->orderBy('id');
            }])
            ->get();

        // Compute token word matches grouped by token and list-type using the source signature
        $matches = $wordMatchService->findMatches($source->signature, [
            'include_boring' => false,
        ]);

        return view('source_names.show', [
            'item' => $source,
            'patterns' => $patterns,
            'matches' => $matches,
        ]);
    }

    // Lightweight step runner to process a small chunk per request
    public function runStep(SourceName $source_name)
    {
        $source = $source_name;
        if ($source->status === 'paused') {
            return response()->json(['ok' => true, 'paused' => true] + $this->progressPayload($source));
        }
        if ($source->status === 'completed') {
            return response()->json(['ok' => true, 'completed' => true] + $this->progressPayload($source));
        }

        if ($source->status !== 'running') {
            $source->status = 'running';
            $source->started_at = $source->started_at ?? now();
            $source->save();
        }

        $startedAt = $source->started_at ?? now();
        $tickStart = microtime(true);

        // Find next pending pattern
        $next = SourceNamePattern::where('source_name_id', $source->id)
            ->where('status', 'pending')
            ->orderBy('popularity_rank')
            ->first();

        if ($next) {
            if ($next->pattern_template =='{title}{forename}{surname}')
            {
                $a=1;
            }
            $next->status = 'processing';
            $next->save();
            $source->current_pattern = $next->pattern_template;
            $source->save();

            // Integrate Anagrammer to generate alter egos for this pattern
            $slots = $this->parsePatternSlots($next->pattern_template);
            $matchesPayload = app(WordMatchService::class)->findMatches($source->signature, [
                // Include boring lists during generation to allow complete phrases like "Vicar Dan Dim"
                'include_boring' => true,
            ]);
            $candidatesByToken = $this->flattenMatchesByToken($matchesPayload['groups'] ?? []);
            $anagrammer = new Anagrammer($candidatesByToken);

            $phrasesMade = 0;
            foreach ($anagrammer->generate($source->name, $slots) as $phrase) {
                // store unique phrases per source
                $created = AlterEgo::firstOrCreate(
                    ['source_name_id' => $source->id, 'phrase' => $phrase],
                    ['source_name_pattern_id' => $next->id]
                );
                if ($created->wasRecentlyCreated) {
                    $source->increment('alteregos_found');
                    $phrasesMade++;
                }
                // cap per step to keep UI responsive (raised to improve coverage for desired phrases like "Vicar Dan Dim")
//                if ($phrasesMade >= 100) break;
            }

            // Mark pattern done for now (single step per pattern)
            $next->status = 'done';
            $next->save();

            $source->increment('patterns_searched');
        }

        // Update elapsed
        $elapsed = (int) (microtime(true) - $tickStart);
        // Use signed diff and clamp to avoid negative elapsed due to clock skew
        $totalElapsed = now()->diffInSeconds($startedAt, false);
        if ($totalElapsed < 0) { $totalElapsed = 0; }
        $source->elapsed_seconds = (int)$totalElapsed;
        $source->save();

        // If no more pending -> complete
        $pendingLeft = SourceNamePattern::where('source_name_id', $source->id)->where('status','pending')->count();
        if ($pendingLeft === 0) {
            $source->status = 'completed';
            $source->completed_at = now();
            $source->current_pattern = null;
            $source->save();
        }

        return response()->json(['ok' => true] + $this->progressPayload($source->fresh()));
    }

    public function pause(SourceName $source_name)
    {
        $source = $source_name;
        $source->status = 'paused';
        $source->paused_at = now();
        $source->save();
        return response()->json(['ok' => true] + $this->progressPayload($source));
    }

    public function resume(SourceName $source_name)
    {
        $source = $source_name;
        $source->status = 'running';
        $source->paused_at = null;
        $source->save();
        return response()->json(['ok' => true] + $this->progressPayload($source));
    }

    public function progress(SourceName $source_name)
    {
        return response()->json(['ok' => true] + $this->progressPayload($source_name));
    }

    public function previewPatterns(Request $request, PatternQueryService $patterns)
    {
        $data = $request->validate([
            'name' => ['required','string','min:5','max:25'],
            'allow_boring' => ['nullable','boolean'],
        ]);
        $name = trim($data['name']);
        $includeBoring = (bool)($data['allow_boring'] ?? false);
        $list = $patterns->listForSource($name, $includeBoring);
        return response()->json(['ok' => true] + $list);
    }

    public function enablePattern(SourceName $source_name, SourceNamePattern $pattern)
    {
        if ($pattern->source_name_id !== $source_name->id) {
            abort(404);
        }
        if ($pattern->status === 'deselected' || $pattern->status === 'done') {
            $pattern->status = 'pending';
            $pattern->save();
            // increment total to search
            $source_name->patterns_total = (int)$source_name->patterns_total + 1;
            if ($source_name->status === 'completed') {
                $source_name->status = 'running';
                $source_name->completed_at = null;
            }
            $source_name->save();
        }
        return response()->json(['ok' => true] + $this->progressPayload($source_name->fresh()));
    }

    public function start(SourceName $source_name, Request $request)
    {
        $source = $source_name;
        $data = $request->validate([
            'templates' => ['array'],
            'templates.*' => ['string'],
        ]);
        $selected = array_values(array_unique(array_map('strval', $data['templates'] ?? [])));

        $patterns = SourceNamePattern::where('source_name_id', $source->id)
            ->orderBy('popularity_rank')
            ->get();

        $wasIdle = $source->status === 'idle';

        if (!empty($selected)) {
            $sel = array_flip($selected);
            foreach ($patterns as $p) {
                $p->status = isset($sel[$p->pattern_template]) ? 'pending' : 'deselected';
                try {
                    $p->save();
                } catch (\Illuminate\Database\QueryException $e) {
                    // Fallback for environments without 'deselected' in enum/check
                    if ($p->status === 'deselected') {
                        $p->status = 'done';
                        $p->save();
                    } else {
                        throw $e;
                    }
                }
            }
            $source->patterns_total = count($selected);
        } else {
            // No explicit selection provided: default to all patterns
            foreach ($patterns as $p) { $p->status = 'pending'; $p->save(); }
            $source->patterns_total = $patterns->count();
        }

        if ($wasIdle) {
            // Reset counters only on first start
            $source->patterns_searched = 0;
            $source->alteregos_found = 0;
            $source->current_pattern = null;
            $source->elapsed_seconds = 0;
            $source->started_at = now();
            $source->paused_at = null;
            $source->completed_at = null;
        }

        $source->status = 'running';
        $source->save();

        return response()->json(['ok' => true] + $this->progressPayload($source->fresh()));
    }

    private function progressPayload(SourceName $s): array
    {
        // Build grouped alter egos by pattern template
        $patterns = SourceNamePattern::where('source_name_id', $s->id)
            ->orderBy('popularity_rank')
            ->get(['id','pattern_template','popularity_rank']);
        $alterEgos = AlterEgo::where('source_name_id', $s->id)
            ->orderBy('id')
            ->get(['source_name_pattern_id','phrase']);
        $byPatternId = [];
        foreach ($alterEgos as $ae) {
            $byPatternId[$ae->source_name_pattern_id ?? 0][] = $ae->phrase;
        }
        $groups = [];
        foreach ($patterns as $p) {
            $phrases = $byPatternId[$p->id] ?? [];
            if (!empty($phrases)) {
                $groups[] = [
                    'pattern' => $p->pattern_template,
                    'rank' => (int)$p->popularity_rank,
                    'phrases' => array_values(array_unique($phrases)),
                ];
            }
        }
        return [
            'id' => $s->id,
            'status' => $s->status,
            'currentPattern' => $s->current_pattern,
            'patternsTotal' => (int)$s->patterns_total,
            'patternsSearched' => (int)$s->patterns_searched,
            'alterEgosFound' => (int)$s->alteregos_found,
            'timeElapsed' => (int)$s->elapsed_seconds,
            // Back-compat flat list
            'alterEgos' => AlterEgo::where('source_name_id', $s->id)->orderByDesc('id')->pluck('phrase'),
            // New grouped payload
            'groups' => $groups,
        ];
    }

    /**
     * Convert a template like "{title}{forename}{surname:2}" into token slots array.
     * @return string[]
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
    private function flattenMatchesByToken(array $groups): array
    {
        $out = [];
        foreach ($groups as $token => $byList) {
            $bucket = [];
            foreach ($byList as $listType => $items) {
                // groups already exclude boring by service unless include_boring option is set
                foreach ($items as $it) {
                    $w = (string)($it['word'] ?? '');
                    if ($w !== '') $bucket[$w] = true; // dedupe
                }
            }
            $out[$token] = array_keys($bucket);
        }
        return $out;
    }

    public function destroy(SourceName $source_name)
    {
        // Cascade deletes handled by FK constraints; just delete the source
        $name = $source_name->name;
        $source_name->delete();
        return redirect()->route('source-names.index')->with('status', "Deleted: {$name}");
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required','array'],
            'ids.*' => ['integer'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        if (empty($ids)) {
            return redirect()->route('source-names.index')->with('status', 'No items selected.');
        }
        \DB::transaction(function () use ($ids) {
            // Eagerly load to allow any model events if needed
            $toDelete = SourceName::whereIn('id', $ids)->get();
            foreach ($toDelete as $s) {
                $s->delete();
            }
        });
        return redirect()->route('source-names.index')->with('status', 'Deleted '.count($ids).' source name(s).');
    }
}
