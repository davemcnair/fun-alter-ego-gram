<?php

namespace App\Services;

use App\Dtos\TargetProgressList;
use App\Dtos\TargetProgressQuery;
use App\Dtos\TargetProgressRow;
use App\Enums\TargetPatternStatus;
use App\Models\AlterEgo;
use App\Models\Target;
use Illuminate\Support\Facades\DB;

final class TargetProgress
{
    public function list(TargetProgressQuery $query): TargetProgressList
    {
        $perPage = max(1, $query->perPage);
        $page = max(1, $query->page);

        $builder = Target::query()
            ->select('targets.*')
            ->addSelect([
                'patterns_total' => DB::table('target_patterns')
                    ->selectRaw('count(*)')
                    ->whereColumn('target_patterns.target_id', 'targets.id'),
                'patterns_filled' => DB::table('target_patterns')
                    ->selectRaw('count(*)')
                    ->whereColumn('target_patterns.target_id', 'targets.id')
                    ->where('status', TargetPatternStatus::FILLED->value),
                'alter_egos_count' => DB::table('alter_egos as ae')
                    ->join('target_signatured_patterns as tsp', 'tsp.id', '=', 'ae.target_signatured_pattern_id')
                    ->join('target_patterns as tp', 'tp.id', '=', 'tsp.target_pattern_id')
                    ->selectRaw('count(*)')
                    ->whereColumn('tp.target_id', 'targets.id'),
                'filled_matches_count' => DB::table('target_token_signatures as tts')
                    ->selectRaw('count(*)')
                    ->whereColumn('tts.target_id', 'targets.id')
                    ->whereNotNull('targets.last_processed_matches_at')
                    ->whereRaw('tts.created_at <= targets.last_processed_matches_at'),
                'new_matches_count' => DB::table('target_token_signatures as tts')
                    ->selectRaw('count(*)')
                    ->whereColumn('tts.target_id', 'targets.id')
                    ->whereNotNull('targets.last_processed_matches_at')
                    ->whereRaw('tts.created_at > targets.last_processed_matches_at'),
                'unseen_matches_count' => DB::table('target_token_signatures as tts')
                    ->selectRaw('count(*)')
                    ->whereColumn('tts.target_id', 'targets.id')
                    ->where(function ($q) {
                        $q->whereNull('targets.matches_seen_at')
                            ->orWhereColumn('tts.created_at', '>', 'targets.matches_seen_at');
                    }),
            ])
            ->orderByDesc('id');

        $items = $builder->paginate($perPage, ['*'], 'page', $page);
        $rows = $items->getCollection()->map(function (Target $target) {
            return new TargetProgressRow(
                id: (int) $target->id,
                name: (string) $target->name,
                patternsFilled: (int) $target->patterns_filled,
                patternsTotal: (int) $target->patterns_total,
                alterEgosCount: (int) $target->alter_egos_count,
                filledMatches: (int) $target->filled_matches_count,
                newMatches: (int) $target->new_matches_count,
                lastProcessed: $target->last_processed_matches_at?->format('Y-m-d H:i:s'),
                unseenMatches: (int) $target->unseen_matches_count,
            );
        });
        $items->setCollection($rows);

        return new TargetProgressList(items: $items);
    }

    /**
     * @return list<string>
     */
    public function setStarred(Target $target, string $phrase, bool $starred): array
    {
        AlterEgo::whereHas('targetSignaturedPattern.targetPattern', function ($q) use ($target) {
            $q->where('target_id', $target->id);
        })
            ->where('phrase', $phrase)
            ->update(['starred' => $starred]);

        return $target->alterEgos()->where('starred', true)->pluck('phrase')->all();
    }
}
