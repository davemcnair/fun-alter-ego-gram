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
        $token = (string) $request->get('token', '');
        $list = (string) $request->get('list', '');
        $perPage = max(1, (int) $request->get('per_page', 25));

        $query = Word::query()->orderBy('id');
        if ($q !== '') {
            $query->where('word', 'like', "%$q%");
        }
        if ($token !== '') {
            $query->where('token_type', $token);
        }
        if ($list !== '') {
            $query->where('list_type', $list);
        }
        $items = $query->paginate($perPage)->appends(['q'=>$q,'token'=>$token,'list'=>$list,'per_page'=>$perPage]);
        return view('words.index', compact('items','q','token','list','perPage'));
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
        $data['signature'] = $this->makeSignature($data['word'] ?? '');
        Word::create($data);
        return redirect()->route('words.index')->with('status', 'Word created.');
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
