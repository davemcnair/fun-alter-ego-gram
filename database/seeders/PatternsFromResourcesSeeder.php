<?php

namespace Database\Seeders;

use App\Models\Pattern;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PatternsFromResourcesSeeder extends Seeder
{
    public function run(): void
    {
        $path = resource_path('patterns/patterns.json');
        if (!file_exists($path)) {
            $this->command?->warn('patterns.json not found in resources/patterns; skipping PatternsFromResourcesSeeder');
            return;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            $this->command?->error('Failed to read ' . $path);
            return;
        }
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            $this->command?->error('Invalid JSON in ' . $path);
            return;
        }
        $includeType = Schema::hasColumn('patterns', 'pattern_type');

        DB::transaction(function () use ($rows, $includeType) {
            // Simple approach: upsert by template and reset ranks exactly as in file
            $rank = 1;
            $seenTemplates = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['template'])) continue;
                $tpl = (string) $row['template'];
                $data = [
                    'template' => $tpl,
                    'popularity_rank' => isset($row['popularity_rank']) ? (int)$row['popularity_rank'] : $rank,
                    'min_total_length' => (int)($row['min_total_length'] ?? 0),
                    'forename_count' => (int)($row['forename_count'] ?? 0),
                    'surname_count' => (int)($row['surname_count'] ?? 0),
                    'has_title' => (bool)($row['has_title'] ?? false),
                    'has_initials' => (bool)($row['has_initials'] ?? false),
                    'has_prefix' => (bool)($row['has_prefix'] ?? false),
                    'has_suffix' => (bool)($row['has_suffix'] ?? false),
                    'has_honorific' => (bool)($row['has_honorific'] ?? false),
                ];
                if ($includeType) {
                    $data['pattern_type'] = (string)($row['pattern_type'] ?? 'exotic');
                }
                Pattern::updateOrCreate(['template' => $tpl], $data);
                $seenTemplates[] = $tpl;
                $rank++;
            }
            // Optionally, remove patterns not present in file. We'll keep existing extras to be safe.
            // But we will normalize ranks to be 1..N by template order just written.
            $all = Pattern::query()->orderBy('popularity_rank')->get();
            $rank = 1;
            foreach ($seenTemplates as $tpl) {
                DB::table('patterns')->where('template', $tpl)->update(['popularity_rank' => $rank++]);
            }
        });
    }
}
