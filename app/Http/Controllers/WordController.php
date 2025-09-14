<?php

namespace App\Http\Controllers;

use App\Models\TokenSignatureWord;
use App\Services\WordMatchService;
use App\Traits\HelpsMatchWords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


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

        // Build base query on token_signature_words joined to token_signatures and tokens
        $query = TokenSignatureWord::query()
            ->join('token_signatures', 'token_signatures.id', '=', 'token_signature_words.token_signature_id')
            ->join('tokens', 'tokens.id', '=', 'token_signatures.token_id')
            ->select([
                'token_signature_words.id',
                'token_signature_words.word',
                'token_signature_words.list_type',
                'token_signature_words.is_deferred',
                'token_signatures.signature as signature',
                'token_signatures.id as token_signature_id',
                'tokens.name as token_type',
                DB::raw('(CASE WHEN token_signature_words.is_deferred = 0 THEN 1 ELSE 0 END) as use_for_search'),
            ])
            ->orderBy('token_signature_words.id');
        if ($q !== '') {
            if ($exact) {
                $query->where('token_signature_words.word', $q);
            } else {
                $like = '%'.$q.'%';
                $query->where('token_signature_words.word', 'like', $like);
            }
        }
        if ($token !== '') {
            $query->where('tokens.name', $token);
        }
        if ($list !== '') {
            $query->where('token_signature_words.list_type', $list);
        }
        if ($hasAnags) {
            // Only rows that have at least one other word in the same token_signature
            $query->whereExists(function ($q2) {
                $q2->selectRaw('1')
                    ->from('token_signature_words as tsw2')
                    ->whereColumn('tsw2.token_signature_id', 'token_signature_words.token_signature_id')
                    ->whereColumn('tsw2.id', '!=', 'token_signature_words.id');
            });
        }

        // Fetch dropdown options (distinct token/list types)
        $tokenOptions = DB::table('tokens')->orderBy('name')->pluck('name')->toArray();
        $listOptions = TokenSignatureWord::query()->select('list_type')->distinct()->orderBy('list_type')->pluck('list_type')->toArray();

        $items = $query->paginate($perPage)->appends([
            'q'=>$q,
            'exact'=>$exact ? 1 : 0,
            'token'=>$token,
            'list'=>$list,
            'per_page'=>$perPage,
            'has_anags'=>$hasAnags ? 1 : 0
        ]);

        // Prepare anagram lists for current page efficiently keyed by token_signature_id
        $tsIds = [];
        foreach ($items as $it) {
            $tsIds[(int)($it->token_signature_id)] = true;
        }
        $anagsByTs = [];
        if (!empty($tsIds)) {
            $rows = TokenSignatureWord::query()
                ->whereIn('token_signature_id', array_keys($tsIds))
                ->orderBy('word')
                ->get(['id','word','token_signature_id']);
            foreach ($rows as $row) {
                $anagsByTs[(int)$row->token_signature_id] = $anagsByTs[(int)$row->token_signature_id] ?? [];
                $anagsByTs[(int)$row->token_signature_id][] = ['id' => (int)$row->id, 'word' => (string)$row->word];
            }
        }
        // Attach helper maps: has anags and list per id excluding itself
        $hasAnagsMap = [];
        $anagsListMap = [];
        foreach ($items as $it) {
            $listAll = $anagsByTs[(int)$it->token_signature_id] ?? [];
            // Exclude self
            $list = array_values(array_filter($listAll, fn($r) => (int)$r['id'] !== (int)$it->id));
            $hasAnagsMap[$it->id] = count($list) > 0;
            if (!empty($list)) $anagsListMap[$it->id] = $list;
        }

        return view('words.index', compact('items','q','exact','token','list','perPage','tokenOptions','listOptions','hasAnags','hasAnagsMap','anagsListMap'));
    }

    public function create()
    {
        // minimal placeholder for form binding
        $word = (object) ['word' => '', 'token_type' => '', 'list_type' => ''];
        return view('words.create', compact('word'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $signature = $this->makeSignature($data['word'] ?? '');
        if ($signature === '') {
            return back()->withErrors(['word' => 'Please include at least one letter.'])->withInput();
        }
        $token = strtolower((string)$data['token_type']);
        $list = (string)$data['list_type'];

        $store = app(\App\Services\WordStoreService::class);

        if (!$request->boolean('confirm', false)) {
            // If there are existing anagrams for this token/signature, show confirmation
            [$existing, $selectedId] = $store->getExistingAnagrams($token, $signature);
            if ($existing->isNotEmpty()) {
                return view('words.confirm_anagrams', [
                    'token_type' => $token,
                    'signature' => $signature,
                    'candidate' => ['word' => $data['word'], 'list_type' => $list],
                    'existing' => $existing,
                    'selected_id' => $selectedId,
                ]);
            }
            // No existing anagrams; create immediately via service (emits event if eligible)
            $store->createNewWordAndMaybeDispatch($token, $data['word'], $list);
            return redirect()->route('words.index')->with('status', 'Word created.');
        }

        // Confirmation submitted
        $choice = (string)$request->get('search_choice', 'new');
        $selectedExistingId = null;
        if (str_starts_with($choice, 'existing:')) {
            $selectedExistingId = (int)substr($choice, strlen('existing:'));
        }

        $created = null;
        if ($selectedExistingId === null && $choice === 'new') {
            $created = $store->createNewWordAndMaybeDispatch($token, $data['word'], $list);
        }

        // Designate representative and possibly emit event if newly created rep is eligible
        $store->designateRepresentativeAndMaybeDispatch($token, $signature, $selectedExistingId, $created);

        return redirect()->route('words.index')->with('status', 'Word saved.');
    }

    public function edit(TokenSignatureWord $word)
    {
        // attach token_type for the form
        $ts = $word->tokenSignature()->with('token')->first();
        $word->token_type = $ts?->token?->name ?? '';
        return view('words.edit', compact('word'));
    }

    public function update(Request $request, TokenSignatureWord $word)
    {
        $data = $this->validateData($request, $word->id);
        $newToken = strtolower((string)$data['token_type']);
        $newWord = (string)$data['word'];
        $newList = (string)$data['list_type'];

        // Recreate via service for simplicity if anything changes substantially
        $svc = app(WordMatchService::class);
        $created = $svc->addTokenWord($newToken, $newWord, $newList);
        if ($created) {
            // remove the old row if different id
            if ((int)$created->id !== (int)$word->id) {
                $word->delete();
            } else {
                // same row, update list_type if needed
                $word->list_type = $newList;
                $word->word = $newWord;
                $word->save();
            }
        }
        return redirect()->route('words.index')->with('status', 'Word updated.');
    }

    public function destroy(TokenSignatureWord $word)
    {
        $word->delete();
        return redirect()->route('words.index')->with('status', 'Word deleted.');
    }

    // Toggle use_for_search representative within an anagram set (AJAX)
    public function toggleSearch(Request $request, TokenSignatureWord $word)
    {
        // Only makes sense if there are anagrams (at least one other in set)
        $count = TokenSignatureWord::query()->where('token_signature_id', $word->token_signature_id)->where('id', '!=', $word->id)->count();
        if ($count === 0) {
            return response()->json(['ok' => false, 'error' => 'No anagrams to designate representative for.'], 400);
        }
        // Reset others to deferred and set this one as active (not deferred)
        TokenSignatureWord::query()->where('token_signature_id', $word->token_signature_id)->update(['is_deferred' => true]);
        $word->is_deferred = false; $word->save();
        return response()->json(['ok' => true]);
    }

    // Promote a word from OK to FUN (AJAX)
    public function promote(Request $request, TokenSignatureWord $word)
    {
        // Only allow promotion for fun-able tokens (based on token name)
        $ts = $word->tokenSignature()->with('token')->first();
        $tokenName = strtolower((string)($ts?->token?->name ?? ''));
        $funAble = ['forename', 'surname'];
        if (!in_array($tokenName, $funAble, true)) {
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
        return $request->validate([
            'word' => ['required','string','max:100'],
            'token_type' => ['required','string','max:50'],
            'list_type' => ['required','string','max:50'],
        ], [], [
            'word' => 'Word',
            'token_type' => 'Token type',
            'list_type' => 'List type',
        ]);
    }
}
