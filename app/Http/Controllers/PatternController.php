<?php

namespace App\Http\Controllers;

use App\Dtos\PatternCatalogQuery;
use App\Models\Pattern;
use App\Services\PatternCatalog;
use Illuminate\Http\Request;

class PatternController extends Controller
{
    public function index(Request $request, PatternCatalog $catalog)
    {
        $query = new PatternCatalogQuery(
            token: (string) $request->get('token', ''),
        );
        $snapshot = $catalog->list($query);

        return view('patterns.index', [
            'snapshot' => $snapshot,
            'token' => $query->token,
        ]);
    }

    public function create()
    {
        $pattern = new Pattern();

        return view('patterns.create', compact('pattern'));
    }

    public function store(Request $request, PatternCatalog $catalog)
    {
        $data = $this->validateData($request);
        $catalog->create($data);

        return redirect()->route('patterns.index')->with('status', 'Pattern created.');
    }

    public function edit(Pattern $pattern)
    {
        return view('patterns.edit', compact('pattern'));
    }

    public function update(Request $request, Pattern $pattern, PatternCatalog $catalog)
    {
        $data = $this->validateData($request, $pattern->id);
        $catalog->update($pattern, $data);

        return redirect()->route('patterns.index')->with('status', 'Pattern updated.');
    }

    public function destroy(Pattern $pattern, PatternCatalog $catalog)
    {
        $catalog->delete($pattern);

        return redirect()->route('patterns.index')->with('status', 'Pattern deleted.');
    }

    public function updateType(Request $request, Pattern $pattern, PatternCatalog $catalog)
    {
        $data = $request->validate([
            'pattern_type' => ['required', 'in:standard,longer,exotic'],
        ]);
        $updated = $catalog->setType($pattern, (string) $data['pattern_type']);

        return response()->json([
            'ok' => true,
            'id' => $updated->id,
            'pattern_type' => $updated->pattern_type,
        ]);
    }

    public function reorder(Request $request, PatternCatalog $catalog)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:patterns,id'],
        ]);
        $count = $catalog->reorder($data['ids']);

        return response()->json(['ok' => true, 'count' => $count]);
    }

    public function export(Request $request, PatternCatalog $catalog)
    {
        $result = $catalog->export();
        if (! ($result['ok'] ?? false)) {
            return response()->json($result, 500);
        }

        return response()->json($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $unique = 'unique:patterns,template';
        if ($ignoreId) {
            $unique .= ','.$ignoreId;
        }

        return $request->validate([
            'template' => ['required', 'string', 'max:255', $unique],
            'popularity_rank' => ['required', 'integer', 'min:1'],
            'min_total_length' => ['required', 'integer', 'min:0'],
            'forename_count' => ['required', 'integer', 'min:0'],
            'surname_count' => ['required', 'integer', 'min:0'],
            'has_title' => ['nullable', 'boolean'],
            'has_initials' => ['nullable', 'boolean'],
            'has_prefix' => ['nullable', 'boolean'],
            'has_suffix' => ['nullable', 'boolean'],
            'has_honorific' => ['nullable', 'boolean'],
        ], [], [
            'template' => 'Pattern',
        ]);
    }
}
