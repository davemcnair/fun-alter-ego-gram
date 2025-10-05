<?php

namespace App\Models;

use Arr;
use DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

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
        $signatureIds = $tokenSignatures->pluck('id')->map(fn($v) => (int)$v)->values();

        // Determine which links already exist - why would they exist?
        $existing = DB::table('target_token_signatures')
            ->where('target_id', $target->id)
            ->whereIn('token_signature_id', $signatureIds)
            ->pluck('token_signature_id')
            ->map(fn($v) => (int)$v)
            ->all();
        $existingSet = array_flip($existing);

        $now = now();
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

        if (!empty($newRows)) {
            DB::table('target_token_signatures')->insert($newRows);
        }
        return Arr::pluck($newRows, 'token_signature_id');
    }

}
