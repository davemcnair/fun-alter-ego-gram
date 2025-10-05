<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TargetTokenSignature extends Model
{
    protected $fillable = [
        'target_id',
        'token_signature_id',
        'usedInPattern',
    ];

    protected $casts = [
        'usedInPattern' => 'boolean',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    public function tokenSignature(): BelongsTo
    {
        return $this->belongsTo(TokenSignature::class);
    }

    /**
     * Link provided TokenSignature rows to the target.
     * Existing links are left untouched. Newly linked rows get timestamps.
     */
    public static function bulkInsertOrIgnore(Target $target, Collection $tokenSignatures): array
    {
        $signatureIds = $tokenSignatures->pluck('id')->toArray();

        // Build word records - FIXED: was using wrong ID
        $wordRecords = [];
        $now = now();
        foreach ($tokenSignatures as $tokenSignature) {
            foreach ($tokenSignature->words as $word) {
                $wordRecords[] = [
                    'target_id' => $target->id,
                    'token_signature_word_id' => $word->id, // FIXED: was token_signature_id
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Bulk insert words if any exist
        if (!empty($wordRecords)) {
            // Use insert ignore or upsert to avoid duplicates
            DB::table('target_token_signature_words')->insertOrIgnore($wordRecords);
        }

        // Find existing signature links
        $existing = DB::table('target_token_signatures')
            ->where('target_id', $target->id)
            ->whereIn('token_signature_id', $signatureIds)
            ->pluck('token_signature_id')
            ->toArray();

        $existingSet = array_flip($existing);

        // Prepare new signature links
        $newRows = [];
        foreach ($signatureIds as $sid) {
            if (!isset($existingSet[$sid])) {
                $newRows[] = [
                    'target_id' => $target->id,
                    'token_signature_id' => $sid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Bulk insert new signature links
        if (!empty($newRows)) {
            DB::table('target_token_signatures')->insert($newRows);
        }

        return array_column($newRows, 'token_signature_id');
    }
}
