<?php

namespace App\Http\Controllers;

use App\Models\Pattern;
use App\Services\PatternCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PatternController extends Controller
{
    public function index(Request $request)
    {
        $token = (string) $request->get('token', '');
        $query = Pattern::query()->orderBy('popularity_rank');
        if ($token !== '') {
            switch (strtolower($token)) {
                case 'title':
                    $query->where('has_title', true);
                    break;
                case 'forename':
                    $query->where('forename_count', '>', 0);
                    break;
                case 'initials':
                    $query->where('has_initials', true);
                    break;
                case 'prefix':
                    $query->where('has_prefix', true);
                    break;
                case 'surname':
                    $query->where('surname_count', '>', 0);
                    break;
                case 'suffix':
                    $query->where('has_suffix', true);
                    break;
                case 'honorific':
                    $query->where('has_honorific', true);
                    break;
            }
        }
        // No pagination per requirement
        $items = $query->get();
        return view('patterns.index', compact('items', 'token'));
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

    // Inline update for pattern type (AJAX)
    public function updateType(Request $request, Pattern $pattern)
    {
        $data = $request->validate([
            'pattern_type' => ['required','in:standard,longer,exotic'],
        ]);
        // If the column doesn't exist (migration not yet applied), avoid a 500 and inform the client
        if (!Schema::hasColumn('patterns', 'pattern_type')) {
            return response()->json(['ok' => false, 'error' => 'pattern_type column not found. Please run migrations.'], 400);
        }
        $pattern->pattern_type = (string)$data['pattern_type'];
        $pattern->save();
        return response()->json(['ok' => true, 'id' => $pattern->id, 'pattern_type' => $pattern->pattern_type]);
    }

    // Reorder by updating popularity_rank according to provided ordered ids
    public function reorder(Request $request, PatternCatalog $catalog)
    {
        $data = $request->validate([
            'ids' => ['required','array','min:1'],
            'ids.*' => ['integer','distinct','exists:patterns,id'],
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
