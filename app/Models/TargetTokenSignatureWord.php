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
     * @param Target $target
     * @param Collection<TokenSignatureWord> $tokenSignatureWords
     * @return Collection<TokenSignatureWord, TargetTokenSignatureWord>
     */
    public static function bulkInsertOrIgnore(Target $target , Collection $tokenSignatureWords): Collection
    {
        $rows = $tokenSignatureWords->map(function ($tsw) use ($target) {
            return [
                'target_id' => $target->id,
                'token_signature_word_id' => $tsw->id,
            ];
        })->toArray();
        if (!empty($rows)) {
            // Idempotent persistence to avoid unique constraint violations on reruns
            // Use insertOrIgnore for SQLite compatibility (upsert with empty update set may degrade to INSERT).
            DB::table('target_token_signature_words')->insertOrIgnore($rows);
        }
        return $target->fresh()->matchingTokenSignatureWords;
    }

}
