<?php

namespace App\Services;

use App\Models\Signature;
use App\Models\Target;
use App\Models\TokenSignature;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WordMatchService
{
    public function findMatchingTokenSignatures(Signature $targetSignature): Collection
    {
        // Don't eager load words here - we'll use a query-based approach in bulkInsertOrIgnore
        // to avoid loading all words into memory at once
        return TokenSignature::query()
            ->whereHas('signature', function ($qs) use ($targetSignature) {
                $qs->where('length', '<=', (int) $targetSignature->length);
                foreach (range('a','z') as $ch) {
                    $n = (int) ($targetSignature->{$ch . '_count'} ?? 0);
                    // Keep exact 0 equality for previous behavior; otherwise allow <= n
                    if ($n > 0) {
                        $qs->where($ch . '_count', '<=', $n);
                    } else {
                        $qs->where($ch . '_count', '=', 0);
                    }
                }
            })
            ->get();
    }

    public function extractTargetTokenSignatureMinimumLengths(Collection $targetTokenSignatures): array
    {
        $storedSignatureBasedMins = [];
        $matchingSignatureBasedMins = [];
        foreach($targetTokenSignatures as $targetTokenSignature) {
            $tokenSignature = $targetTokenSignature->tokenSignature;
            $length = (int) ($tokenSignature->signature->length ?? 0);
            $token_id = $tokenSignature->token_id;
            $storedSignatureBasedMins[$token_id] = $tokenSignature->token->min_length;
            if (!isset($matchingSignatureBasedMins[$token_id]) || $length < $matchingSignatureBasedMins[$token_id]) {
                $matchingSignatureBasedMins[$token_id] = $length;
            }
        }
        return array($storedSignatureBasedMins, $matchingSignatureBasedMins);
    }

    /**
     * Extract minimum lengths using SQL queries to avoid loading all models into memory.
     * This is memory-efficient for targets with many token signatures.
     */
    public function extractTargetTokenSignatureMinimumLengthsFromQuery(Target $target): array
    {
        // Use SQL aggregation to compute minimums without loading all models
        $results = DB::table('target_token_signatures as tts')
            ->join('token_signatures as ts', 'ts.id', '=', 'tts.token_signature_id')
            ->join('signatures as s', 's.id', '=', 'ts.signature_id')
            ->join('tokens as t', 't.id', '=', 'ts.token_id')
            ->where('tts.target_id', $target->id)
            ->select([
                'ts.token_id',
                't.min_length as stored_min',
                DB::raw('MIN(s.length) as matched_min')
            ])
            ->groupBy('ts.token_id', 't.min_length')
            ->get();

        $storedSignatureBasedMins = [];
        $matchingSignatureBasedMins = [];
        
        foreach ($results as $row) {
            $token_id = (int) $row->token_id;
            $storedSignatureBasedMins[$token_id] = (int) $row->stored_min;
            $matchingSignatureBasedMins[$token_id] = (int) $row->matched_min;
        }

        return [$storedSignatureBasedMins, $matchingSignatureBasedMins];
    }
}
