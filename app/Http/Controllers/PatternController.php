<?php

namespace App\Http\Controllers;

use App\Models\Pattern;
use Illuminate\Http\Request;

class PatternController extends Controller
{
    public function index(Request $request)
    {
        $q = (string) $request->get('q', '');
        $perPage = max(1, (int) $request->get('per_page', 25));
        $query = Pattern::query()->orderBy('popularity_rank');
        if ($q !== '') {
            $query->where('template', 'like', "%$q%");
        }
        $items = $query->paginate($perPage)->appends(['q' => $q, 'per_page' => $perPage]);
        return view('patterns.index', compact('items', 'q', 'perPage'));
    }

    public function create()
    {
        $pattern = new Pattern();
        return view('patterns.create', compact('pattern'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        Pattern::create($data);
        return redirect()->route('patterns.index')->with('status', 'Pattern created.');
    }

    public function edit(Pattern $pattern)
    {
        return view('patterns.edit', compact('pattern'));
    }

    public function update(Request $request, Pattern $pattern)
    {
        $data = $this->validateData($request, $pattern->id);
        $pattern->update($data);
        return redirect()->route('patterns.index')->with('status', 'Pattern updated.');
    }

    public function destroy(Pattern $pattern)
    {
        $pattern->delete();
        return redirect()->route('patterns.index')->with('status', 'Pattern deleted.');
    }

    /**
     * @param Request $request
     * @param int|null $ignoreId
     * @return array<string, mixed>
     */
    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $unique = 'unique:patterns,template';
        if ($ignoreId) { $unique .= ',' . $ignoreId; }
        return $request->validate([
            'template' => ['required','string','max:255',$unique],
            'popularity_rank' => ['required','integer','min:1'],
            'min_total_length' => ['required','integer','min:0'],
            'forename_count' => ['required','integer','min:0'],
            'surname_count' => ['required','integer','min:0'],
            'has_title' => ['nullable','boolean'],
            'has_initials' => ['nullable','boolean'],
            'has_prefix' => ['nullable','boolean'],
            'has_suffix' => ['nullable','boolean'],
            'has_honorific' => ['nullable','boolean'],
        ], [], [
            'template' => 'Pattern',
        ]);
    }
}
