<?php

namespace App\Http\Controllers;

use App\Models\Target;
use App\Models\TargetPattern;
use App\Models\AlterEgo;
use App\Services\TargetCreationService;
use App\Traits\HelpsMatchWords;
use DB;
use Illuminate\Http\Request;

class TargetController extends Controller
{
    use HelpsMatchWords;

    public function index()
    {
        $items = Target::paginate(15);
        return view('targets.index', compact('items'));
    }

    public function store(Request $request, TargetCreationService $createService)
    {
        $data = $request->validate([
            'name' => ['required','string','min:5','max:25', "regex:/^[A-Za-z .,\-']+$/"],
            'allow_boring' => ['nullable','boolean'],
        ]);
        $includeBoring = (bool)($data['allow_boring'] ?? false);

        $result = $createService->create($data['name'], $includeBoring);
        /** @var Target $target */
        $target = $result['target'];

        return redirect()->route('targets.show', $target);
    }

    public function show(Target $target)
    {
        $target->fresh();
        \Log::info('TargetController.show', ['target' => $target]);
        return view('targets.show', $this->lookupProgressPayload($target));
    }

//    public function pause(Target $target)
//    {
//        $target->status = 'paused';
//        $target->save();
//        return response()->json(['ok' => true] + $this->lookupProgressPayload($target));
//    }
//
//    public function resume(Target $target)
//    {
//        $target->status = 'running';
//        $target->save();
//
//        // Enqueue remaining pending patterns
//        $pending = TargetPattern::where('target_id', $target->id)
//            ->where('status', 'pending')
//            ->orderBy('popularity_rank')
//            ->pluck('id');
//        foreach ($pending as $pid) {
//            // Job expects only the TargetNamePattern ID
//            $dispatch = FillPatternSignaturesJob::dispatch((int)$pid);
//            $queue = config('search.queue');
//            if (!empty($queue)) { $dispatch->onQueue($queue); }
//        }
//
//        return response()->json(['ok' => true] + $this->lookupProgressPayload($target));
//    }

    public function progress(Target $target)
    {
        return response()->json(['ok' => true] + $this->lookupProgressPayload($target));
    }

    public function star(Target $target, Request $request)
    {
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
//        // Try update; if target already exists, delete the source and star the existing
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
            'matchedWords' => $this->buildMatchedWords($s),
        ];
    }

    /**
     * Build grouped token word matches for the Target Results page.
     * Returns an array like [ tokenName => [ listType => [ [id, word], ... ] ] ]
     */
    private function buildMatchedWords(Target $s): array
    {
        // Eager load token and tokenSignature for grouping
        $rows = $s->tokenSignatureWords()
            ->with(['tokenSignature.token'])
            ->get();
        if ($rows->isEmpty()) return [];
        $out = [];
        foreach ($rows as $row) {
            $token = optional($row->tokenSignature->token)->name ?? '';
            if ($token === '') continue;
            $list = (string)($row->list_type ?? '');
            if ($list === '') continue;
            if (!isset($out[$token])) $out[$token] = [];
            if (!isset($out[$token][$list])) $out[$token][$list] = [];
            $out[$token][$list][] = [
                'id' => (int) $row->id,
                'word' => (string) $row->word,
            ];
        }
        // Sort words alphabetically within each group for stable UI
        foreach ($out as $token => &$lists) {
            foreach ($lists as $list => &$items) {
                usort($items, function($a, $b){ return strcasecmp($a['word'] ?? '', $b['word'] ?? ''); });
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
}
