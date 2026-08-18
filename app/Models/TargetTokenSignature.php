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
     * 
     * Uses query-based approach to avoid loading all words into memory.
     */
    public static function bulkInsertOrIgnore(Target $target, Collection $tokenSignatures): array
    {
        $signatureIds = $tokenSignatures->pluck('id')->toArray();
        $now = now();

        // Use direct SQL query to insert word records without loading models into memory
        // This is much more memory-efficient than loading all TokenSignatureWord models
        if (!empty($signatureIds)) {
            // Insert word records using a subquery - avoids loading all words into PHP memory
            // Process in chunks to handle large signature lists and prevent query size limits
            $chunkSize = 100; // Process signatures in chunks
            $signatureChunks = array_chunk($signatureIds, $chunkSize);
            
            foreach ($signatureChunks as $chunk) {
                // Use raw SQL with proper parameter binding to insert word records directly
                // This avoids loading TokenSignatureWord models into memory
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $params = array_merge(
                    [$target->id, $now, $now],
                    $chunk
                );
                
                // Detect database driver to use correct INSERT IGNORE syntax
                $driver = DB::getDriverName();
                $insertIgnoreSyntax = match($driver) {
                    'sqlite' => 'INSERT OR IGNORE',
                    'mysql', 'mariadb' => 'INSERT IGNORE',
                    'pgsql' => 'INSERT',
                    default => 'INSERT IGNORE',
                };
                
                // For PostgreSQL, we need to use ON CONFLICT instead
                if ($driver === 'pgsql') {
                    DB::statement("
                        INSERT INTO target_token_signature_words 
                        (target_id, token_signature_word_id, created_at, updated_at)
                        SELECT 
                            ? as target_id,
                            tsw.id as token_signature_word_id,
                            ? as created_at,
                            ? as updated_at
                        FROM token_signature_words tsw
                        WHERE tsw.token_signature_id IN ($placeholders)
                        ON CONFLICT (target_id, token_signature_word_id) DO NOTHING
                    ", $params);
                } else {
                    // Use DB::statement() for INSERT IGNORE/OR IGNORE with SELECT subquery
                    DB::statement("
                        $insertIgnoreSyntax INTO target_token_signature_words 
                        (target_id, token_signature_word_id, created_at, updated_at)
                        SELECT 
                            ? as target_id,
                            tsw.id as token_signature_word_id,
                            ? as created_at,
                            ? as updated_at
                        FROM token_signature_words tsw
                        WHERE tsw.token_signature_id IN ($placeholders)
                    ", $params);
                }
                
                // Free memory after each chunk
                unset($chunk, $placeholders, $params);
            }
            unset($signatureChunks);
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
