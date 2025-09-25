<?php

namespace App\Http\Controllers;

use App\Models\Signature;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Models\AlterEgo;
use App\Models\TokenSignatureWord;
use App\Services\TargetService;
use App\Services\WordMatchService;
use App\Support\NameNormalizer;
use App\Traits\HelpsMatchWords;
use App\Jobs\FillPatternSignaturesJob;
use App\Traits\ScalesJobs;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TargetController extends Controller
{
    use HelpsMatchWords, ScalesJobs;


    public function addWord(Target $target, Request $request, WordMatchService $wordMatchService, TargetService $targetService)
    {
        // Perform explicit validation so we can return a consistent JSON error shape on failure
        $validator = Validator::make($request->all(), [
            'token_type' => ['required','string'],
            'word' => ['required','string','min:1'],
            'list_type' => ['nullable','string'],
        ]);
        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();
            Log::warning('TargetController.addWord: validation failed', [
                'target_id' => $target->id,
                'errors' => $errors,
                'input' => $request->only(['token_type','word','list_type']),
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }
        $data = $validator->validated();

        // Normalize and validate list type (default to ok)
        $listType = strtolower(trim((string)($data['list_type'] ?? 'ok')));
        if (!in_array($listType, ['ok','fun'], true)) {
            return response()->json(['ok' => false, 'error' => 'Invalid list_type'], 422);
        }

        $wordMatchService->addTokenWord($data['token_type'], $data['word'], $listType);

        // Step 1: Find matches and link to this target
        $wordMatchService->linkMatchesToTarget($target);

        // Step 2: compute min lengths (id-keyed arrays)
        $targetService->fillMatchedPatternsForTarget($target->fresh());

        return response()->json(['ok' => true] + $this->lookupProgressPayload($target->fresh()));
    }

    public function index()
    {
        $items = Target::query()
            ->select('targets.*')
            ->addSelect([
                'new_matches_count' => DB::table('target_token_signature_words as ttsw')
                    ->selectRaw('count(*)')
                    ->whereColumn('ttsw.target_id', 'targets.id')
                    ->when(DB::raw('targets.matches_seen_at'), function ($q) {
                        // Count rows strictly newer than the last seen timestamp
                        $q->whereRaw('ttsw.created_at > targets.matches_seen_at');
                    }),
            ])
            ->orderByDesc('id')
            ->paginate(25);

        return view('targets.index', compact('items'));
    }

    public function store(Request $request, TargetService $targetService)
    {
        $data = $request->validate([
            'name' => ['required','string','min:1','max:100'],
            'allow_boring' => ['nullable','boolean'],
        ]);
        $includeBoring = (bool)($data['allow_boring'] ?? false);

        // Ensure normalization yields a non-empty signature
        $canonical = NameNormalizer::canonicalKey($data['name']);
        if ($canonical === '') {
            return back()->withErrors(['name' => 'Name is invalid after normalization'])->withInput();
        }

        // Create/find minimal Target with queued status
        $display = NameNormalizer::displayName($data['name']);
        $sigDto = NameNormalizer::anagramSignature($display);
        $signature = Signature::firstOrCreate(['signature' => $sigDto->signature], $sigDto->defaults);
        $target = Target::firstOrCreate(
            ['normalized_key' => $canonical],
            [
                'name' => $display,
                'status' => 'queued',
                'signature_id' => $signature->id,
            ]
        );

        // Always set status to queued if newly created or if idle
        if (in_array($target->status, ['idle'])) {
            $target->status = 'queued';
            $target->save();
        }

        // Dispatch async creation job
        $this->scaledDispatch(\App\Jobs\CreateTargetJob::class, $target->id, $includeBoring);

        return redirect()->route('targets.show', $target);
    }

    public function apiStore(Request $request, TargetService $targetService)
    {
        $data = $request->validate([
            'name' => ['required','string','min:1','max:100'],
            'allow_boring' => ['nullable','boolean'],
        ]);
        $includeBoring = (bool)($data['allow_boring'] ?? false);

        $canonical = NameNormalizer::canonicalKey($data['name']);
        if ($canonical === '') {
            return response()->json(['ok' => false, 'error' => 'Name is invalid after normalization'], 422);
        }

        $display = NameNormalizer::displayName($data['name']);
        $sigDto = NameNormalizer::anagramSignature($display);
        $signature = Signature::firstOrCreate(['signature' => $sigDto->signature], $sigDto->defaults);
        $target = Target::firstOrCreate(
            ['normalized_key' => $canonical],
            [
                'name' => $display,
                'status' => 'queued',
                'signature_id' => $signature->id,
            ]
        );

        if (in_array($target->status, ['idle'])) {
            $target->status = 'queued';
            $target->save();
        }

        $this->scaledDispatch(\App\Jobs\CreateTargetJob::class, $target->id, $includeBoring);

        try { \Log::info('api.targets.store', ['target_id' => $target->id, 'created' => (bool)$target->wasRecentlyCreated]); } catch (Throwable $e) {}

        return response()->json([
            'ok' => true,
            'id' => $target->id,
            'redirect' => route('api.targets.show', $target),
        ]);
    }

    // JSON-only Targets index for API v1
    public function apiIndex(Request $request)
    {
        $perPage = (int) $request->query('per_page', 25);
        $perPage = $perPage > 0 && $perPage <= 100 ? $perPage : 25;

        $query = Target::query()
            ->select('targets.*')
            ->addSelect([
                'new_matches_count' => DB::table('target_token_signature_words as ttsw')
                    ->selectRaw('count(*)')
                    ->whereColumn('ttsw.target_id', 'targets.id')
                    ->when(DB::raw('targets.matches_seen_at'), function ($q) {
                        $q->whereRaw('ttsw.created_at > targets.matches_seen_at');
                    }),
            ])
            ->orderByDesc('id');

        $paginator = $query->paginate($perPage);

        return response()->json([
            'ok' => true,
            'data' => $paginator->items(),
            'meta' => [
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'total_pages' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    // JSON-only Target detail for API v1
    public function apiShow(Target $target)
    {
        $target->fresh();
        $payload = $this->lookupProgressPayload($target);

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $target->id,
                'name' => $target->name,
                'status' => $target->status,
                'updated_at' => optional($target->updated_at)?->toIso8601String(),
                'metrics' => [
                    'patterns_processed' => $payload['patternsProcessedCount'],
                    'patterns_total' => $payload['patternsCount'],
                    'alter_egos' => $payload['alterEgosCount'],
                    'signature_indexed_patterns' => $payload['signatureIndexedPatternsCount'],
                ],
                'starred' => $payload['starred'],
            ],
        ]);
    }

    public function show(Target $target)
    {
//        $target->fresh();
        $data = $this->lookupProgressPayload($target);
        return view('targets.show', $data);
    }

    public function progress(Target $target)
    {
//        $target->fresh();
        $data = $this->lookupProgressPayload($target);
        // Lightweight observability for UI polling
        try {
            \Log::info('target.progress', [
                'target_id' => $target->id,
                'status' => $target->status,
                'patterns_total' => $data['patternsCount'],
                'patterns_completed' => $target->patterns()->where('status','done')->count(),
                'patterns_running' => $target->patterns()->where('status','processing')->count(),
                'patterns_pending' => $target->patterns()->whereIn('status', ['pending','deferred'])->count(),
                'alter_egos' => $data['alterEgosCount'],
            ]);
        } catch (Throwable $e) { /* ignore */ }
        return response()->json([
            'ok' => true,
            'status' => $target->status,
            'updated_at' => optional($target->updated_at)?->toIso8601String(),
            'patterns' => [
                'total' => $data['patternsCount'],
                'completed' => $target->patterns()->where('status','done')->count(),
                'running' => $target->patterns()->where('status','processing')->count(),
                'pending' => $target->patterns()->whereIn('status', ['pending','deferred'])->count(),
            ],
            'signatureIndexedPatternsCount' => $data['signatureIndexedPatternsCount'],
            'alterEgosCount' => $data['alterEgosCount'],
        ]);
    }

    public function newMatches(Target $target)
    {
        Log::info('TargetController.newMatches: request received', [
            'target_id' => $target->id,
        ]);
        $rows = DB::table('target_token_signature_words as t')
            ->join('token_signature_words as w', 'w.id', '=', 't.token_signature_word_id')
            ->join('token_signatures as s', 's.id', '=', 'w.token_signature_id')
            ->join('tokens as tok', 'tok.id', '=', 's.token_id')
            ->where('t.target_id', $target->id)
            ->when($target->matches_seen_at, function($q) use ($target){
                $q->where('t.created_at', '>', $target->matches_seen_at);
            })
            ->orderBy('tok.name')
            ->orderBy('w.list_type')
            ->orderBy('w.word')
            ->get(['w.id as id', 'tok.name as token', 'w.list_type', 'w.word']);
        return response()->json([
            'ok' => true,
            'count' => $rows->count(),
            'items' => $rows,
        ]);
    }

    public function processNewMatches(Target $target)
    {
        Log::info('TargetController.processNewMatches: request received', [
            'target_id' => $target->id,
        ]);
        // Basic rate-limit: avoid rapid reprocessing within 30 seconds
        $key = 'target:'.$target->id.':process_new_matches_at';
        $now = time();
        try {
            $last = (int) (cache()->get($key) ?? 0);
            if ($last && ($now - $last) < 30) {
                return response()->json(['ok' => false, 'error' => 'Please wait before retrying'], 429);
            }
        } catch (Throwable $e) { /* ignore cache errors */ }

        // Determine how many matches are new for processing since the last cycle
        $count = DB::table('target_token_signature_words as t')
            ->where('t.target_id', $target->id)
            ->when($target->last_processed_matches_at, function($q) use ($target){
                $q->where('t.created_at', '>', $target->last_processed_matches_at);
            })
            ->count();

        // Enqueue fill/expand for each target pattern
        $patterns = TargetPattern::where('target_id', $target->id)->orderBy('popularity_rank')->pluck('id')->all();
        $attempted = 0;
        foreach ($patterns as $pid) {
            $attempted++;
            $this->scaledDispatch(FillPatternSignaturesJob::class, (int)$pid);
        }

        try { cache()->put($key, $now, 60); } catch (Throwable $e) {}

        return response()->json([
            'ok' => true,
            'patterns_attempted' => $attempted,
            'new_matches_count' => $count,
        ]);
    }

    public function markMatchesSeen(Target $target)
    {
        try { $target->matches_seen_at = now(); $target->save(); } catch (Throwable $e) {}
        return response()->json(['ok' => true]);
    }

    public function star(Target $target, Request $request)
    {
        Log::info('TargetController.star: request received', [
            'target_id' => $target->id,
            'ajax' => $request->ajax(),
            'headers' => [
                'x-requested-with' => $request->header('X-Requested-With'),
                'accept' => $request->header('Accept'),
            ],
            'payload_preview' => [ 'phrase_len' => strlen((string)$request->input('phrase','')) ],
        ]);
        $data = $request->validate([
            'phrase' => ['required','string'],
        ]);
        AlterEgo::whereHas('targetSignatureIndexedPattern.targetPattern', function($q) use ($target){
            $q->where('target_id', $target->id);
        })
            ->where('phrase', $data['phrase'])
            ->update(['starred' => true]);
        return response()->json(['ok' => true] + $this->lookupProgressPayload($target->fresh())) ;
    }

    public function unstar(Target $target, Request $request)
    {
        $data = $request->validate([
            'phrase' => ['required','string'],
        ]);
        AlterEgo::whereHas('targetSignatureIndexedPattern.targetPattern', function($q) use ($target){
            $q->where('target_id', $target->id);
        })
            ->where('phrase', $data['phrase'])
            ->update(['starred' => false]);
        return response()->json(['ok' => true] + $this->lookupProgressPayload($target->fresh())) ;
    }
//
//    public function rephrase(Target $target, Request $request)
//    {
//        $data = $request->validate([
//            'from' => ['required','string'],
//            'to' => ['required','string','different:from'],
//        ]);
//        $from = (string)$data['from'];
//        $to = trim((string)$data['to']);
//        if ($to === '') {
//            return response()->json(['ok' => false, 'error' => 'Empty phrase'], 422);
//        }
//        // Try update; if target already exists, delete the target and star the existing
//        $existing = AlterEgo::where('target_id', $target->id)->where('phrase', $to)->first();
//        if ($existing) {
//            AlterEgo::where('target_id', $target->id)->where('phrase', $from)->delete();
//            $existing->starred = true; $existing->save();
//        } else {
//            // Update the phrase; if row not found, return 404-ish JSON
//            $row = AlterEgo::where('target_id', $target->id)->where('phrase', $from)->first();
//            if (!$row) {
//                return response()->json(['ok' => false, 'error' => 'Original phrase not found'], 404);
//            }
//            $row->phrase = $to;
//            $row->starred = true; // star the saved variant by default
//            try {
//                $row->save();
//            } catch (\Throwable $e) {
//                return response()->json(['ok' => false, 'error' => 'Failed to save phrase'], 500);
//            }
//        }
//        return response()->json(['ok' => true] + $this->lookupProgressPayload($target->fresh()));
//    }

    private function lookupProgressPayload(Target $s): array
    {
        return [
            'item' => $s,
            'patternsProcessedCount' => $s->patterns()->where('status','done')->count(),
            'patternsCount' => $s->patterns->count(),
            'patternsLive' => $s->patterns()->whereIn('status', ['done','processing'])->get()
                ->map(fn($pattern) => $this->lookupPatternPayload($s->status, $pattern)),
            'patternsWaiting' => $s->patterns()->whereIn('status', ['pending','deferred'])->get()
                ->map(fn($pattern) => $this->lookupPatternPayload($s->status, $pattern)),
            'signatureIndexedPatternsCount' => $s->signatureIndexedPatterns()->count(),
            'alterEgosCount' => $s->alterEgos()->count(),
            'starred' => $s->alterEgos()->where('starred', true)->pluck('phrase')->all(),
            'matchedWords' => $this->buildMatchedWords($s->matchingTokenSignatureWords),
            'hasUncommitted' => $s->matchingTokenSignatureWords
                ->filter( fn($w) => is_null($w->committed_at))
                ->isNotEmpty()
        ];
    }

    /**
     * Build grouped token word matches for the Target Results page.
     * Returns an array like [ tokenName => [ listType => [ [id, word], ... ] ] ]
     */
    private function buildMatchedWords(Collection $matchingTokenSignatureWords): array
    {
        $out = [];
        /** @var TokenSignatureWord $tokenSignatureWord */
        foreach ($matchingTokenSignatureWords as $tokenSignatureWord) {
            $token = $tokenSignatureWord->tokenSignature->token->name;
            $list = $tokenSignatureWord->list_type;
            if (!isset($out[$token])) $out[$token] = [];
            if (!isset($out[$token][$list])) $out[$token][$list] = [];
            $out[$token][$list][] = [
                'id' => $tokenSignatureWord->id,
                'word' => $tokenSignatureWord->word,
            ];
        }
        // Sort words alphabetically within each group for stable UI
        foreach ($out as $token => &$lists) {
            foreach ($lists as $list => &$items) {
                usort($items, function($a, $b) {
                    return strcasecmp($a['word'], $b['word']);
                });
            }
        }
        return $out;
    }

    private function lookupPatternPayload(string $status, TargetPattern $pattern): array
    {
        $signatureIndexedPatterns = $pattern->signatureIndexedPatterns;
        $alterEgos = $pattern->alterEgos;
        return [
            'id' => $pattern->id,
            'status' => $status,
            'template' => optional($pattern->pattern)->template,
            'signatureIndexedPatternsCount' => $signatureIndexedPatterns->count(),
            'alterEgosCount' => $alterEgos->count(),
            'elapsed_ms' => $pattern->elapsed_ms,
            'started_at' => $pattern->started_at,
            'finished_at' => $pattern->finished_at,
            'signatureIndexedPatterns' => $signatureIndexedPatterns,
            'alterEgos' => $alterEgos,
        ];
    }

    public function destroy(Target $target)
    {
        // Cascade deletes handled by FK constraints; just delete the target
        $name = $target->name;
        $target->delete();
        return redirect()->route('targets.index')->with('status', "Deleted: {$name}");
    }

    public function apiDestroy(Target $target)
    {
        $id = $target->id;
        $target->delete();
        return response()->json(['ok' => true, 'id' => $id]);
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required','array'],
            'ids.*' => ['integer'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        if (empty($ids)) {
            return redirect()->route('targets.index')->with('status', 'No items selected.');
        }
        DB::transaction(function () use ($ids) {
            // Eagerly load to allow any model events if needed
            $toDelete = Target::whereIn('id', $ids)->get();
            foreach ($toDelete as $s) {
                $s->delete();
            }
        });
        return redirect()->route('targets.index')->with('status', 'Deleted '.count($ids).' target(s).');
    }

    public function apiBulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required','array'],
            'ids.*' => ['integer'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $deleted = 0;
        DB::transaction(function () use ($ids, &$deleted) {
            $toDelete = Target::whereIn('id', $ids)->get();
            foreach ($toDelete as $s) {
                $s->delete();
                $deleted++;
            }
        });
        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }
}
