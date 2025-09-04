<?php

namespace App\Http\Controllers;

use App\Models\Word;
use App\Traits\HelpsMatchWords;
use Illuminate\Http\Request;

class WordController extends Controller
{
    use HelpsMatchWords;

    public function index(Request $request)
    {
        $q = (string) $request->get('q', '');
        $exact = (bool) $request->boolean('exact', false);
        $token = (string) $request->get('token', '');
        $list = (string) $request->get('list', '');
        $hasAnags = (bool) $request->boolean('has_anags', false);
        $perPage = max(1, (int) $request->get('per_page', 25));

        // Build base query
        $query = Word::query()->orderBy('id');
        if ($q !== '') {
            if ($exact) {
                $query->where('word', $q);
            } else {
                $like = '%'.$q.'%';
                $query->where('word', 'like', $like);
            }
        }
        if ($token !== '') {
            $query->where('token_type', $token);
        }
        if ($list !== '') {
            $query->where('list_type', $list);
        }
        if ($hasAnags) {
            // Only rows that have at least one other word with same signature and token_type
            $query->whereExists(function ($q2) {
                $q2->selectRaw('1')
                    ->from('words as w2')
                    ->whereColumn('w2.signature', 'words.signature')
                    ->whereColumn('w2.token_type', 'words.token_type')
                    ->whereColumn('w2.id', '!=', 'words.id');
            });
        }

        // Fetch dropdown options (distinct token/list types)
        $tokenOptions = Word::query()->select('token_type')->distinct()->orderBy('token_type')->pluck('token_type')->toArray();
        $listOptions = Word::query()->select('list_type')->distinct()->orderBy('list_type')->pluck('list_type')->toArray();

        $items = $query->paginate($perPage)->appends([
            'q'=>$q,
            'exact'=>$exact ? 1 : 0,
            'token'=>$token,
            'list'=>$list,
            'per_page'=>$perPage,
            'has_anags'=>$hasAnags ? 1 : 0
        ]);

        // Prepare anagram lists for current page efficiently
        $sigTokPairs = [];
        foreach ($items as $it) {
            $sig = (string) ($it->signature ?? '');
            $tok = (string) ($it->token_type ?? '');
            if ($sig !== '' && $tok !== '') {
                $sigTokPairs[$tok.'|'.$sig] = ['token'=>$tok,'signature'=>$sig];
            }
        }
        $anagsByKey = [];
        if (!empty($sigTokPairs)) {
            $tokens = array_values(array_unique(array_map(fn($r) => $r['token'], $sigTokPairs)));
            // Query all anagrams for these signatures per token_type
            $queryAnags = Word::query()
                ->whereIn('token_type', $tokens)
                ->whereIn('signature', array_values(array_unique(array_map(fn($r) => $r['signature'], $sigTokPairs))))
                ->orderBy('word');
            $rows = $queryAnags->get(['id','word','token_type','signature']);
            foreach ($rows as $row) {
                $key = ($row->token_type ?? '').'|'.($row->signature ?? '');
                $anagsByKey[$key] = $anagsByKey[$key] ?? [];
                $anagsByKey[$key][] = ['id' => (int)$row->id, 'word' => (string)$row->word];
            }
        }
        // Attach helper maps: has anags and list per id excluding itself
        $hasAnagsMap = [];
        $anagsListMap = [];
        foreach ($items as $it) {
            $key = (string)($it->token_type).'|'.(string)($it->signature);
            $listAll = $anagsByKey[$key] ?? [];
            // Exclude self
            $list = array_values(array_filter($listAll, fn($r) => (int)$r['id'] !== (int)$it->id));
            $hasAnagsMap[$it->id] = count($list) > 0;
            if (!empty($list)) $anagsListMap[$it->id] = $list;
        }

        return view('words.index', compact('items','q','exact','token','list','perPage','tokenOptions','listOptions','hasAnags','hasAnagsMap','anagsListMap'));
    }

    public function create()
    {
        $word = new Word();
        return view('words.create', compact('word'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        // Auto-generate signature if not provided or mismatched
        $signature = $this->makeSignature($data['word'] ?? '');
        if ($signature === '') {
            return back()->withErrors(['word' => 'Please include at least one letter.'])->withInput();
        }
        $token = strtolower((string)$data['token_type']);
        $list = (string)$data['list_type'];

        // If confirmation step not provided, show the anagram set for selection
        if (!$request->boolean('confirm', false)) {
            $existing = Word::query()
                ->where('token_type', $token)
                ->where('signature', $signature)
                ->orderBy('word')
                ->get();
            if ($existing->count() > 0) {
                // Preselect existing search word if any
                $selectedId = $existing->firstWhere('use_for_search', true)->id ?? null;
                return view('words.confirm_anagrams', [
                    'token_type' => $token,
                    'signature' => $signature,
                    'candidate' => ['word' => $data['word'], 'list_type' => $list],
                    'existing' => $existing,
                    'selected_id' => $selectedId,
                ]);
            } else {
                // No existing anagrams; create immediately as search representative
                Word::create([
                    'word' => $data['word'],
                    'token_type' => $token,
                    'list_type' => $list,
                    'signature' => $signature,
                    'use_for_search' => true,
                ]);
                return redirect()->route('words.index')->with('status', 'Word created.');
            }
        }

        // Confirmation submitted
        $choice = (string)$request->get('search_choice', 'new');
        $existing = Word::query()
            ->where('token_type', $token)
            ->where('signature', $signature)
            ->get();

        $selectedExistingId = null;
        if (str_starts_with($choice, 'existing:')) {
            $selectedExistingId = (int)substr($choice, strlen('existing:'));
        }

        $createdId = null;
        if ($selectedExistingId === null && $choice === 'new') {
            // Create the new word (phrase-only initially; will set flag below)
            $row = Word::create([
                'word' => $data['word'],
                'token_type' => $token,
                'list_type' => $list,
                'signature' => $signature,
                'use_for_search' => false,
            ]);
            $createdId = (int)$row->id;
        }

        // Toggle flags: one search word per (token, signature)
        // First, reset all existing in set to phrase-only
        Word::query()->where('token_type', $token)->where('signature', $signature)->update(['use_for_search' => false]);

        // Determine final search id
        $searchId = $selectedExistingId ?? $createdId;
        if ($searchId) {
            Word::query()->where('id', $searchId)->update(['use_for_search' => true]);
        } else {
            // If somehow none selected, fallback to: set newly created (if any) or the first existing as search
            $fallback = $createdId ?: ($existing->first()->id ?? null);
            if ($fallback) {
                Word::query()->where('id', $fallback)->update(['use_for_search' => true]);
            }
        }

        return redirect()->route('words.index')->with('status', 'Word saved with anagram search designation.');
    }

    public function edit(Word $word)
    {
        return view('words.edit', compact('word'));
    }

    public function update(Request $request, Word $word)
    {
        $data = $this->validateData($request, $word->id);
        $data['signature'] = $this->makeSignature($data['word'] ?? '');
        $word->update($data);
        return redirect()->route('words.index')->with('status', 'Word updated.');
    }

    public function destroy(Word $word)
    {
        $word->delete();
        return redirect()->route('words.index')->with('status', 'Word deleted.');
    }

    // Toggle use_for_search representative within an anagram set (AJAX)
    public function toggleSearch(Request $request, Word $word)
    {
        // Only makes sense if there are anagrams (at least one other in set)
        $count = Word::query()->where('token_type', $word->token_type)->where('signature', $word->signature)->where('id', '!=', $word->id)->count();
        if ($count === 0) {
            return response()->json(['ok' => false, 'error' => 'No anagrams to designate representative for.'], 400);
        }
        // Reset others to phrase-only and set this one as search
        Word::query()->where('token_type', $word->token_type)->where('signature', $word->signature)->update(['use_for_search' => false]);
        $word->use_for_search = true; $word->save();
        return response()->json(['ok' => true]);
    }

    // Promote a word from OK to FUN (AJAX)
    public function promote(Request $request, Word $word)
    {
        // Only allow promotion for fun-able tokens
        $funAble = ['forename', 'surname'];
        if (!in_array(strtolower((string)$word->token_type), $funAble, true)) {
            return response()->json(['ok' => false, 'error' => 'Token not fun-able'], 400);
        }
        // No-op if already fun
        if (strtolower((string)$word->list_type) === 'fun') {
            return response()->json(['ok' => true, 'already' => true]);
        }
        $word->list_type = 'fun';
        $word->save();
        return response()->json(['ok' => true]);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $unique = 'unique:words,word';
        if ($ignoreId) { $unique .= ',' . $ignoreId; }
        return $request->validate([
            'word' => ['required','string','max:100',$unique],
            'token_type' => ['required','string','max:50'],
            'list_type' => ['required','string','max:50'],
        ], [], [
            'word' => 'Word',
            'token_type' => 'Token type',
            'list_type' => 'List type',
        ]);
    }
}
