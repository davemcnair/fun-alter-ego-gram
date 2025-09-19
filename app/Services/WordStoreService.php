<?php

namespace App\Services;

use App\Events\TokenWordAdded;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;

/**
 * WordStoreService
 * -----------------
 * Encapsulates the business logic used by the Words controller when storing a word,
 * so it is easier to unit test without dealing with HTTP concerns or views.
 */
class WordStoreService
{
    public function __construct(private readonly WordMatchService $wordMatchService)
    {
    }

    /**
     * Load existing anagrams for a token/signature pair and determine the default selected id.
     * Returns [existing: collection, selected_id: ?int]
     */
    public function getExistingAnagrams(string $tokenName, string $signature): array
    {
        $tokenSignature = $this->findTokenSignature($tokenName, $signature);
        if (!$tokenSignature) {
            return [collect(), null];
        }
        $existing = TokenSignatureWord::query()
            ->where('token_signature_id', $tokenSignature->id)
            ->orderBy('word')
            ->get();
        $selectedId = optional($existing->firstWhere('is_deferred', false))->id;

        return [$existing, $selectedId ? (int)$selectedId : null];
    }

    /**
     * Create a new TokenSignatureWord and, if eligible (fun/ok and not deferred), dispatch TokenWordAdded.
     * Returns the created row.
     */
    public function addWordAndSearchIfSearchable(string $tokenName, string $word, string $listType): TokenSignatureWord
    {

        if (in_array((string)$created->list_type, ['fun','ok'], true) && !$created->is_deferred) {
            event(new TokenWordAdded((int)$created->id));
        }
        return $created;
    }

    /**
     * Designate representative within the anagram set for the given token/signature by marking
     * all words under that signature deferred, then making the selected id non-deferred. If a newly
     * created word is the selected representative and is eligible (fun/ok), dispatch TokenWordAdded.
     * Returns the final selected id (or null if not resolved).
     */
    public function designateRepresentativeAndMaybeDispatch(
        string $tokenName,
        string $signature,
        ?int $selectedExistingId,
        ?TokenSignatureWord $created
    ): ?int {
        $ts = $this->findTokenSignature($tokenName, $signature);
        if (!$ts) return null;

        // Reset all under this signature to deferred
        TokenSignatureWord::query()->where('token_signature_id', $ts->id)->update(['is_deferred' => true]);
        $finalId = $selectedExistingId ?? ($created?->id ?? null);
        if ($finalId) {
            TokenSignatureWord::query()->where('id', (int)$finalId)->update(['is_deferred' => false]);
        }

        // If the created word became the representative and is eligible, dispatch event
        if ($created && $finalId && (int)$finalId === (int)$created->id) {
            $fresh = TokenSignatureWord::find((int)$created->id);
            if ($fresh && in_array((string)$fresh->list_type, ['fun','ok'], true) && !$fresh->is_deferred) {
                try { event(new TokenWordAdded((int)$fresh->id)); } catch (\Throwable $e) { /* swallow */ }
            }
        }
        return $finalId ? (int)$finalId : null;
    }

    private function findTokenSignature(string $tokenName, string $signature): ?TokenSignature
    {
        return TokenSignature::query()
            ->join('tokens', 'tokens.id', '=', 'token_signatures.token_id')
            ->where('tokens.name', $tokenName)
            ->where('token_signatures.signature', $signature)
            ->select('token_signatures.*')
            ->first();
    }
}
