<?php

namespace App\Http\Controllers;

use App\Dtos\WordCatalogQuery;
use App\Models\TokenSignatureWord;
use App\Services\WordCatalog;
use App\Support\NameNormalizer;
use Illuminate\Http\Request;
use Throwable;


class WordController extends Controller
{

    public function index(Request $request, WordCatalog $catalog)
    {
        $query = new WordCatalogQuery(
            q: (string) $request->get('q', ''),
            exact: $request->boolean('exact', false),
            token: (string) $request->get('token', ''),
            list: (string) $request->get('list', ''),
            hasAnagrams: $request->boolean('has_anags', false),
            perPage: (int) $request->get('per_page', 25),
            page: (int) $request->get('page', 1),
        );
        $snapshot = $catalog->list($query);
        $snapshot->items->appends([
            'q' => $query->q,
            'exact' => $query->exact ? 1 : 0,
            'token' => $query->token,
            'list' => $query->list,
            'per_page' => $snapshot->items->perPage(),
            'has_anags' => $query->hasAnagrams ? 1 : 0,
        ]);

        return view('words.index', [
            'snapshot' => $snapshot,
            'q' => $query->q,
            'exact' => $query->exact,
            'token' => $query->token,
            'list' => $query->list,
            'perPage' => $snapshot->items->perPage(),
            'hasAnags' => $query->hasAnagrams,
        ]);
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

    public function destroy(TokenSignatureWord $word, WordCatalog $catalog)
    {
        $catalog->delete($word);
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
