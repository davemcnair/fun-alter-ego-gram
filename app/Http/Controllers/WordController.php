<?php

namespace App\Http\Controllers;

use App\Models\TokenSignatureWord;
use App\Services\WordCatalog;
use App\Support\NameNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;


class WordController extends Controller
{

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
                'token_signatures.id as token_signature_id',
                'tokens.name as token_type',
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

        $hasUncommitted = TokenSignatureWord::query()->whereNull('committed_at')->exists();
        return view('words.index', compact('items','q','exact','token','list','perPage','tokenOptions','listOptions','hasAnags','hasAnagsMap','anagsListMap','hasUncommitted'));
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
        $signature = NameNormalizer::anagramSignature($data['word'] ?? '')->signature;
        if ($signature === '') {
            return back()->withErrors(['word' => 'Please include at least one letter.'])->withInput();
        }
        $token = strtolower((string)$data['token_type']);
        $list = (string)$data['list_type'];

        $catalog = app(WordCatalog::class);

        if (!$request->boolean('confirm', false)) {
            [$existing, $selectedId] = $catalog->existingAnagrams($token, $signature);
            if ($existing->isNotEmpty()) {
                return view('words.confirm_anagrams', [
                    'token_type' => $token,
                    'signature' => $signature,
                    'candidate' => ['word' => $data['word'], 'list_type' => $list],
                    'existing' => $existing,
                    'selected_id' => $selectedId,
                ]);
            }
            $catalog->add($token, $data['word'], $list);
            return redirect()->route('words.index')->with('status', 'Word created.');
        }

        $choice = (string)$request->get('search_choice', 'new');
        $selectedExistingId = null;
        if (str_starts_with($choice, 'existing:')) {
            $selectedExistingId = (int)substr($choice, strlen('existing:'));
        }

        $created = null;
        if ($selectedExistingId === null && $choice === 'new') {
            $created = $catalog->add($token, $data['word'], $list);
        }

        $catalog->chooseRepresentative($token, $signature, $selectedExistingId, $created);

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

        $created = app(WordCatalog::class)->replace($word, $newToken, $newWord, $newList);
        return redirect()->route('words.index')->with('status', 'Word updated.');
    }

    public function destroy(TokenSignatureWord $word)
    {
        $word->delete();
        return redirect()->route('words.index')->with('status', 'Word deleted.');
    }

    // Promote a word from OK to FUN (AJAX)
    public function promote(Request $request, TokenSignatureWord $word, WordCatalog $catalog)
    {
        $result = $catalog->promote($word);
        if (! ($result['ok'] ?? false)) {
            return response()->json($result, 400);
        }
        return response()->json($result);
    }

    public function demote(Request $request, TokenSignatureWord $word, WordCatalog $catalog)
    {
        $result = $catalog->demote($word);
        if (! ($result['ok'] ?? false)) {
            return response()->json($result, 400);
        }
        return response()->json($result);
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

    // Commit resources by merging uncommitted DB words back to resources/token_words
    public function commitResources(Request $request)
    {
        // Simple lock to avoid concurrent commits
        $lockFile = storage_path('app/commit_words.lock');
        $fh = @fopen($lockFile, 'c');
        if ($fh === false) {
            return response()->json(['ok' => false, 'error' => 'Unable to create lock'], 500);
        }
        if (!@flock($fh, LOCK_EX | LOCK_NB)) {
            return response()->json(['ok' => false, 'error' => 'Commit already in progress'], 409);
        }

        try {
            $result = app(WordCatalog::class)->commit();
            return response()->json([
                'ok' => true,
                'committed_count' => (int)($result['committed_count'] ?? 0),
                'backup' => $result['backup'] ?? null,
                'sample_changes' => array_slice($result['changes'] ?? [], 0, 5),
            ]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Commit failed', 'message' => $e->getMessage()], 500);
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
            @unlink($lockFile); // best-effort cleanup
        }
    }
}
