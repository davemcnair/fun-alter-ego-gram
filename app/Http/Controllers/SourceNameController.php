<?php

namespace App\Http\Controllers;

use App\Models\SourceName;
use App\Models\SourceNamePattern;
use App\Models\AlterEgo;
use App\Services\ListPatternsService;
use App\Services\WordMatchService;
use App\Services\Anagrammer;
use App\Services\SignatureFillService;
use App\Models\SignaturedPattern;
use App\Traits\HelpsMatchWords;
use Illuminate\Http\Request;
use App\Jobs\FillPatternSignaturesJob;
use Illuminate\Support\Facades\DB;

class SourceNameController extends Controller
{
    use HelpsMatchWords;

    public function index()
    {
        try {
            $items = SourceName::withCount('alterEgos')->orderByDesc('id')->paginate(15);
            return view('source_names.index', compact('items'));
        } catch (\Throwable $e) {
            // If database is not ready or any issue occurs, show a lightweight welcome/setup page
            $details = config('app.debug') ? (string) $e->getMessage() : null;
            return response()->view('welcome_min', [
                'error' => $details,
            ], 200);
        }
    }

    public function create()
    {
        return view('source_names.create');
    }

    public function store(Request $request, ListPatternsService $patternsService)
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
        $patterns = $patternsService->listForSource($name, $includeBoring);

        // Restrict to standard patterns only for new searches
        try {
            $standardTemplates = \DB::table('patterns')
                ->where('pattern_type', 'standard')
                ->pluck('template')
                ->toArray();
        } catch (\Throwable $e) {
            $standardTemplates = [];
        }
        if (!empty($standardTemplates)) {
            $rows = array_values(array_filter($rows, function($r) use ($standardTemplates) {
                $tpl = is_array($r) ? ($r['template'] ?? '') : ($r->template ?? '');
                return in_array($tpl, $standardTemplates, true);
            }));
        }

        $source = SourceName::create([
            'name' => $name,
            'signature' => $signature,
            'status' => 'idle',
            'patterns_total' => count($rows),
        ]);

        $bulk = [];
        $now = now();
        foreach ($rows as $r) {
            $tpl = is_array($r) ? ($r['template'] ?? '') : ($r->template ?? '');
            $rank = (int)(is_array($r) ? ($r['popularity_rank'] ?? 0) : ($r->popularity_rank ?? 0));
            if ($tpl === '' || $rank <= 0) continue;
            $bulk[] = [
                'source_name_id' => $source->id,
                'pattern_template' => $tpl,
                'popularity_rank' => $rank,
                'status' => 'pending',
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
        // patterns_total already set to total rows initially
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

    // Step: process one pending pattern synchronously (fallback when no queue worker), then return progress
    public function runStep(SourceName $source_name, WordMatchService $wordMatchService)
    {
        $source = $source_name->fresh();
        if (in_array($source->status, ['paused', 'completed'], true)) {
            return response()->json(['ok' => true] + $this->progressPayload($source));
        }
        // Find next pending pattern by rank
        $pattern = SourceNamePattern::where('source_name_id', $source->id)
            ->where('status', 'pending')
            ->orderBy('popularity_rank')
            ->first();
        if ($pattern) {
            // Atomically claim pattern
            $updated = DB::table('source_name_patterns')
                ->where('id', $pattern->id)
                ->where('status', 'pending')
                ->update(['status' => 'processing', 'updated_at' => now()]);
            if ($updated > 0 || $pattern->status === 'processing') {
                // Proceed with processing
                $source->current_pattern = $pattern->pattern_template;
                $source->save();

                $patternTokenPositions = $this->parsePatternSlots($pattern->pattern_template);

                // Include boring lists during generation so phrases can be complete
                $matchesPayload = $wordMatchService->findMatches($source->signature, [
                    'include_boring' => true,
                ]);
                $candidatesByToken = $this->flattenMatchesByTokenWithSignatures(is_array($matchesPayload) ? $matchesPayload : []);

                // Also persist signature-only fills for this pattern (best-effort)
                try {
                    $sigFill = new SignatureFillService();
                    foreach ($sigFill->generateSignaturePatterns($source->signature, $patternTokenPositions, array_reduce(array_keys($candidatesByToken), function($acc, $tok) use ($candidatesByToken){
                        // Convert list of {word,signature} entries to [word => sig]
                        $map = [];
                        foreach ($candidatesByToken[$tok] as $pair) {
                            $w = (string)($pair['word'] ?? ''); $s = (string)($pair['signature'] ?? '');
                            if ($w !== '' && $s !== '') $map[$w] = $s;
                        }
                        $acc[$tok] = $map;
                        return $acc;
                    }, [])) as $sigPattern) {
                        SignaturedPattern::firstOrCreate([
                            'source_name_pattern_id' => $pattern->id,
                            'signatured_pattern' => $sigPattern,
                        ]);
                    }
                } catch (\Throwable $e) { /* ignore */ }

                $anagrammer = new Anagrammer($candidatesByToken);
                $phrasesMade = 0;
                foreach ($anagrammer->generate($source->name, $patternTokenPositions) as $phrase) {
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

                // Mark pattern done and update counters
                $pattern->status = 'done';
                $pattern->save();
                $source->increment('patterns_searched');

                // Update elapsed seconds
                $startedAt = $source->started_at ?? now();
                $totalElapsed = now()->diffInSeconds($startedAt, false);
                if ($totalElapsed < 0) { $totalElapsed = 0; }
                $source->elapsed_seconds = (int) $totalElapsed;

                // Check if more pending remain
                $pendingLeft = SourceNamePattern::where('source_name_id', $source->id)
                    ->where('status', 'pending')
                    ->count();
                if ($pendingLeft === 0) {
                    $source->status = 'completed';
                    $source->completed_at = now();
                    $source->current_pattern = null;
                } else {
                    $source->current_pattern = null; // clear current between patterns
                }
                $source->save();
            }
        } else {
            // No pending patterns: ensure completed state if appropriate
            $pendingLeft = SourceNamePattern::where('source_name_id', $source->id)
                ->where('status', 'pending')
                ->count();
            if ($pendingLeft === 0 && $source->status !== 'completed') {
                $source->status = 'completed';
                $source->completed_at = now();
                $source->current_pattern = null;
                $source->save();
            }
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

        // Enqueue remaining pending patterns
        $pending = SourceNamePattern::where('source_name_id', $source->id)
            ->where('status', 'pending')
            ->orderBy('popularity_rank')
            ->pluck('id');
        foreach ($pending as $pid) {
            $dispatch = FillPatternSignaturesJob::dispatch($source->id, (int)$pid);
            $queue = config('search.queue');
            if (!empty($queue)) { $dispatch->onQueue($queue); }
        }

        return response()->json(['ok' => true] + $this->progressPayload($source));
    }

    public function progress(SourceName $source_name)
    {
        return response()->json(['ok' => true] + $this->progressPayload($source_name));
    }

    public function star(SourceName $source_name, Request $request)
    {
        $data = $request->validate([
            'phrase' => ['required','string'],
        ]);
        AlterEgo::where('source_name_id', $source_name->id)
            ->where('phrase', $data['phrase'])
            ->update(['starred' => true]);
        return response()->json(['ok' => true] + $this->progressPayload($source_name->fresh())) ;
    }

    public function unstar(SourceName $source_name, Request $request)
    {
        $data = $request->validate([
            'phrase' => ['required','string'],
        ]);
        AlterEgo::where('source_name_id', $source_name->id)
            ->where('phrase', $data['phrase'])
            ->update(['starred' => false]);
        return response()->json(['ok' => true] + $this->progressPayload($source_name->fresh())) ;
    }

    public function rephrase(SourceName $source_name, Request $request)
    {
        $data = $request->validate([
            'from' => ['required','string'],
            'to' => ['required','string','different:from'],
        ]);
        $from = (string)$data['from'];
        $to = trim((string)$data['to']);
        if ($to === '') {
            return response()->json(['ok' => false, 'error' => 'Empty phrase'], 422);
        }
        // Try update; if target already exists, delete the source and star the existing
        $existing = AlterEgo::where('source_name_id', $source_name->id)->where('phrase', $to)->first();
        if ($existing) {
            AlterEgo::where('source_name_id', $source_name->id)->where('phrase', $from)->delete();
            $existing->starred = true; $existing->save();
        } else {
            // Update the phrase; if row not found, return 404-ish JSON
            $row = AlterEgo::where('source_name_id', $source_name->id)->where('phrase', $from)->first();
            if (!$row) {
                return response()->json(['ok' => false, 'error' => 'Original phrase not found'], 404);
            }
            $row->phrase = $to;
            $row->starred = true; // star the saved variant by default
            try {
                $row->save();
            } catch (\Throwable $e) {
                return response()->json(['ok' => false, 'error' => 'Failed to save phrase'], 500);
            }
        }
        return response()->json(['ok' => true] + $this->progressPayload($source_name->fresh()));
    }

    public function previewPatterns(Request $request, ListPatternsService $patterns)
    {
        $data = $request->validate([
            'name' => ['required','string','min:5','max:25'],
            'allow_boring' => ['nullable','boolean'],
        ]);
        $name = trim($data['name']);
        $includeBoring = (bool)($data['allow_boring'] ?? false);
        $rows = $patterns->listForSource($name, $includeBoring);
        return response()->json(['ok' => true, 'count' => count($rows), 'rows' => $rows]);
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

            // If running, enqueue this pattern for background processing
            if ($source_name->status === 'running') {
                $dispatch = FillPatternSignaturesJob::dispatch($source_name->id, $pattern->id);
                $queue = config('search.queue');
                if (!empty($queue)) { $dispatch->onQueue($queue); }
            }
        }
        return response()->json(['ok' => true] + $this->progressPayload($source_name->fresh()));
    }

    public function start(SourceName $source)
    {
        // Select all patterns for this source;
        // filtering to standard types was already applied at creation time.
        $sourceNamePatterns = SourceNamePattern::where('source_name_id', $source->id)
            ->orderBy('popularity_rank')
            ->get();

        $wasIdle = $source->status === 'idle';

        foreach ($sourceNamePatterns as $p) {
            $p->status = 'pending';
            $p->save();
        }
        $source->patterns_total = $sourceNamePatterns->count();

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

        // Enqueue all pending patterns for background processing
        $pendingIds = $sourceNamePatterns->pluck('id');
        $queue = config('search.queue');
        foreach ($pendingIds as $pid) {
            $dispatch = FillPatternSignaturesJob::dispatch($pid);
            if (!empty($queue)) { $dispatch->onQueue($queue); }
        }

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
            ->get(['source_name_pattern_id','phrase','starred']);
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
        $starred = AlterEgo::where('source_name_id', $s->id)
            ->where('starred', true)
            ->orderBy('id')
            ->pluck('phrase')
            ->all();
        return [
            'id' => $s->id,
            'status' => $s->status,
            'currentPattern' => $s->current_pattern,
            'patternsTotal' => (int)$s->patterns_total,
            'patternsSearched' => (int)$s->patterns_searched,
            'alterEgosFound' => (int)$s->alteregos_found,
            'timeElapsed' => (int)$s->elapsed_seconds,
//            'alterEgos' => AlterEgo::where('source_name_id', $s->id)->orderByDesc('id')->pluck('phrase'),
            'groups' => $groups,
            'starred' => $starred,
        ];
    }


    /**
     * Flatten WordMatchService groups to token => list of {word, signature} for Anagrammer.
     * @param array<string,array<string,array<int,array{word:string,signature:string}>>> $groups
     * @return array<string,array<int,array{word:string,signature:string}>>
     */
    private function flattenMatchesByTokenWithSignatures(array $groups): array
    {
        $out = [];
        foreach ($groups as $token => $byList) {
            $bucket = [];
            foreach ($byList as $items) {
                // groups already exclude boring by service unless include_boring option is set
                foreach ($items as $it) {
                    $w = (string)($it['word'] ?? '');
                    $sig = (string)($it['signature'] ?? '');
                    if ($w === '' || $sig === '') continue;
                    $bucket[$w] = $sig; // dedupe by word
                }
            }
            $out[$token] = array_map(function($w) use ($bucket){ return ['word'=>$w, 'signature'=>$bucket[$w]]; }, array_keys($bucket));
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
