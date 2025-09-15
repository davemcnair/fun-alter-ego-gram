<?php

namespace App\Models;

use DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class TargetTokenSignatureWord extends Model
{
    protected $fillable = [
        'target_id',
        'token_signature_word_id',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    public function tokenSignatureWord(): BelongsTo
    {
        return $this->belongsTo(TokenSignatureWord::class);
    }

    /**
     * Link provided TokenSignatureWord rows to the target.
     * Existing links are left untouched. Newly linked rows get timestamps.
     *
     * @param Target $target
     * @param Collection<TokenSignatureWord> $tokenSignatureWords
     * @return Collection<TokenSignatureWord, TargetTokenSignatureWord>
     */
    public static function bulkInsertOrIgnore(Target $target , Collection $tokenSignatureWords): Collection
    {
        if ($tokenSignatureWords->isEmpty()) {
            return $target->fresh()->matchingTokenSignatureWords;
        }

        $wordIds = $tokenSignatureWords->pluck('id')->map(fn($v) => (int)$v)->values();

        // Determine which links already exist
        $existing = DB::table('target_token_signature_words')
            ->where('target_id', $target->id)
            ->whereIn('token_signature_word_id', $wordIds)
            ->pluck('token_signature_word_id')
            ->map(fn($v) => (int)$v)
            ->all();
        $existingSet = array_flip($existing);

        $now = now();
        $newRows = [];
        foreach ($wordIds as $wid) {
            if (!isset($existingSet[$wid])) {
                $newRows[] = [
                    'target_id' => $target->id,
                    'token_signature_word_id' => $wid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (!empty($newRows)) {
            DB::table('target_token_signature_words')->insert($newRows);
        }

        return $target->fresh()->matchingTokenSignatureWords;
    }

}
