<?php

namespace App\Http\Controllers;

use App\Models\Pattern;
use App\Models\SourceName;
use App\Models\SourceNamePattern;
use App\Models\AlterEgo;
use App\Services\ListPatternsService;

use App\Traits\HelpsMatchWords;
use DB;
use Illuminate\Http\Request;
use App\Jobs\FillPatternSignaturesJob;

class SourceNameController extends Controller
{
    use HelpsMatchWords;

    public function index()
    {
        $items = SourceName::paginate(15);
        return view('source_names.index', compact('items'));
    }

    public function store(Request $request, ListPatternsService $patternsService)
    {
        $data = $request->validate([
            'name' => ['required','string','min:5','max:25', "regex:/^[A-Za-z .,\-']+$/"],
            'allow_boring' => ['nullable','boolean'],
        ]);
        $name = trim($data['name']);
        $signature = $this->makeSignature($name);

        $includeBoring = (bool)($data['allow_boring'] ?? false);

        $standardShortEnoughPatterns = $patternsService->listWithinMinLength(strlen($signature));
        $filteredPatterns = $patternsService->filterForSource($signature, $standardShortEnoughPatterns, $includeBoring);

        $source = SourceName::create([
            'name' => $name,
            'signature' => $signature,
            'status' => 'idle',
        ]);

        $bulk = [];
        $now = now();
        /** @var Pattern $pattern */
        foreach ($filteredPatterns as $pattern) {
            $bulk[] = [
                'source_name_id' => $source->id,
                'pattern_template' => $pattern->template,
                'popularity_rank' => $pattern->popularity_rank,
                // Restrict pending to standard patterns for new searches
                'status' => $pattern->pattern_type == 'standard' ? 'pending' : 'deferred',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        SourceNamePattern::insert($bulk);

        // Enqueue all pending patterns for background processing
        $pendingIds = $source->patterns()->where('status','pending')->pluck('id');
        $queue = config('search.queue');
        foreach ($pendingIds as $pid) {
            $dispatch = FillPatternSignaturesJob::dispatch((int)$pid);
            if (!empty($queue)) { $dispatch->onQueue($queue); }
        }

        $source->status = 'running';
        $source->save();

        return redirect()->route('source-names.show', $source);
    }

    public function show(SourceName $source_name)
    {
        return view('source_names.show', $this->lookupProgressPayload($source_name));
    }

//    public function pause(SourceName $source_name)
//    {
//        $source = $source_name;
//        $source->status = 'paused';
//        $source->save();
//        return response()->json(['ok' => true] + $this->lookupProgressPayload($source));
//    }
//
//    public function resume(SourceName $source_name)
//    {
//        $source = $source_name;
//        $source->status = 'running';
//        $source->save();
//
//        // Enqueue remaining pending patterns
//        $pending = SourceNamePattern::where('source_name_id', $source->id)
//            ->where('status', 'pending')
//            ->orderBy('popularity_rank')
//            ->pluck('id');
//        foreach ($pending as $pid) {
//            // Job expects only the SourceNamePattern ID
//            $dispatch = FillPatternSignaturesJob::dispatch((int)$pid);
//            $queue = config('search.queue');
//            if (!empty($queue)) { $dispatch->onQueue($queue); }
//        }
//
//        return response()->json(['ok' => true] + $this->lookupProgressPayload($source));
//    }

    public function progress(SourceName $source_name)
    {
        return response()->json(['ok' => true] + $this->lookupProgressPayload($source_name));
    }

    public function star(SourceName $source_name, Request $request)
    {
        $data = $request->validate([
            'phrase' => ['required','string'],
        ]);
        AlterEgo::where('source_name_id', $source_name->id)
            ->where('phrase', $data['phrase'])
            ->update(['starred' => true]);
        return response()->json(['ok' => true] + $this->lookupProgressPayload($source_name->fresh())) ;
    }

    public function unstar(SourceName $source_name, Request $request)
    {
        $data = $request->validate([
            'phrase' => ['required','string'],
        ]);
        AlterEgo::where('source_name_id', $source_name->id)
            ->where('phrase', $data['phrase'])
            ->update(['starred' => false]);
        return response()->json(['ok' => true] + $this->lookupProgressPayload($source_name->fresh())) ;
    }
//
//    public function rephrase(SourceName $source_name, Request $request)
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
//        $existing = AlterEgo::where('source_name_id', $source_name->id)->where('phrase', $to)->first();
//        if ($existing) {
//            AlterEgo::where('source_name_id', $source_name->id)->where('phrase', $from)->delete();
//            $existing->starred = true; $existing->save();
//        } else {
//            // Update the phrase; if row not found, return 404-ish JSON
//            $row = AlterEgo::where('source_name_id', $source_name->id)->where('phrase', $from)->first();
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
//        return response()->json(['ok' => true] + $this->lookupProgressPayload($source_name->fresh()));
//    }

    private function lookupProgressPayload(SourceName $s): array
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
            'wordMatches' => $s->wordMatches,
        ];
    }
    private function lookupPatternPayload(string $status, SourceNamePattern $pattern): array
    {
        $signatureIndexedPatterns = $pattern->signatureIndexedPatterns;
        $alterEgos = $pattern->alterEgos;
        return [
            'id' => $pattern->id,
            'status' => $status,
            'template' => $pattern->template,
            'signatureIndexedPatternsCount' => $signatureIndexedPatterns->count(),
            'alterEgosCount' => $alterEgos->count(),
            'signatureIndexedPatterns' => $signatureIndexedPatterns,
            'alterEgos' => $alterEgos,
        ];
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
        DB::transaction(function () use ($ids) {
            // Eagerly load to allow any model events if needed
            $toDelete = SourceName::whereIn('id', $ids)->get();
            foreach ($toDelete as $s) {
                $s->delete();
            }
        });
        return redirect()->route('source-names.index')->with('status', 'Deleted '.count($ids).' source name(s).');
    }
}
