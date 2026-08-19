<?php

namespace App\Services;

use App\Dtos\SignatureDto;
use App\Events\TokenWordAdded;
use App\Models\Signature;
use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
use App\Support\NameNormalizer;
use DateTime;
use Throwable;

final class WordCatalog
{
    public function __construct(private readonly WordCommitService $commit)
    {
    }

    public function add(
        string $tokenName,
        string $word,
        string $listType,
        ?DateTime $committedAt = null
    ): TokenSignatureWord {
        $token = Token::where('name', $tokenName)->first();
        $signatureDto = SignatureDto::fromWord($word);
        $signature = Signature::firstOrCreate(['signature' => $signatureDto->signature], $signatureDto->defaults);
        $tokenSignature = TokenSignature::firstOrCreate([
            'token_id' => $token->id,
            'signature_id' => $signature->id,
        ]);

        $useWordImmediately = $listType === 'fun';

        $tokenSignatureWord = TokenSignatureWord::firstOrCreate(
            [
                'token_signature_id' => $tokenSignature->id,
                'list_type' => $listType,
                'word' => NameNormalizer::letterString($word),
            ],
            [
                'is_deferred' => ! $useWordImmediately,
                'committed_at' => $committedAt,
            ]
        );

        if ($listType === 'fun') {
            $firstNonFun = $tokenSignature->words()
                ->where('list_type', '!=', 'fun')
                ->orderBy('id')
                ->first();
            if ($firstNonFun && ! $firstNonFun->is_deferred) {
                $firstNonFun->is_deferred = true;
                $firstNonFun->save();
            }
        }

        $this->notifyIfSearchable($tokenSignatureWord, $committedAt);

        return $tokenSignatureWord;
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, TokenSignatureWord>, 1: ?int}
     */
    public function existingAnagrams(string $tokenName, string $signature): array
    {
        $tokenSignature = $this->findTokenSignature($tokenName, $signature);
        if (! $tokenSignature) {
            return [collect(), null];
        }
        $existing = TokenSignatureWord::query()
            ->where('token_signature_id', $tokenSignature->id)
            ->orderBy('word')
            ->get();
        $selectedId = optional($existing->firstWhere('is_deferred', false))->id;

        return [$existing, $selectedId ? (int) $selectedId : null];
    }

    public function chooseRepresentative(
        string $tokenName,
        string $signature,
        ?int $selectedExistingId,
        ?TokenSignatureWord $created
    ): ?int {
        $ts = $this->findTokenSignature($tokenName, $signature);
        if (! $ts) {
            return null;
        }

        TokenSignatureWord::query()->where('token_signature_id', $ts->id)->update(['is_deferred' => true]);
        $finalId = $selectedExistingId ?? ($created?->id ?? null);
        if ($finalId) {
            TokenSignatureWord::query()->where('id', (int) $finalId)->update(['is_deferred' => false]);
        }

        if ($created && $finalId && (int) $finalId === (int) $created->id) {
            $fresh = TokenSignatureWord::find((int) $created->id);
            if ($fresh) {
                $this->notifyIfSearchable($fresh, null);
            }
        }

        return $finalId ? (int) $finalId : null;
    }

    /**
     * @return array{ok: bool, already?: bool, error?: string}
     */
    public function promote(TokenSignatureWord $word): array
    {
        $word->loadMissing('tokenSignature.token');
        $tokenName = strtolower((string) ($word->tokenSignature?->token?->name ?? ''));
        if (! in_array($tokenName, ['forename', 'surname'], true)) {
            return ['ok' => false, 'error' => 'Token not fun-able'];
        }
        if (strtolower((string) $word->list_type) === 'fun') {
            return ['ok' => true, 'already' => true];
        }
        $word->list_type = 'fun';
        $word->is_deferred = false;
        $word->save();
        $word->refresh();
        $this->notifyIfSearchable($word, null);

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, already?: bool, error?: string}
     */
    public function demote(TokenSignatureWord $word): array
    {
        $word->loadMissing('tokenSignature.token');
        $tokenName = strtolower((string) ($word->tokenSignature?->token?->name ?? ''));
        if ($tokenName !== 'surname') {
            return ['ok' => false, 'error' => 'Token not demotable'];
        }
        if (strtolower((string) $word->list_type) === 'boring') {
            return ['ok' => true, 'already' => true];
        }
        $word->list_type = 'boring';
        $word->save();

        return ['ok' => true];
    }

    public function replace(TokenSignatureWord $word, string $tokenName, string $newWord, string $listType): TokenSignatureWord
    {
        $created = $this->add($tokenName, $newWord, $listType);
        if ((int) $created->id !== (int) $word->id) {
            $word->delete();
        }

        return $created;
    }

    public function commit(): array
    {
        return $this->commit->commit();
    }

    private function notifyIfSearchable(TokenSignatureWord $word, ?DateTime $committedAt): void
    {
        if ($committedAt !== null) {
            return;
        }
        if (! in_array((string) $word->list_type, ['fun', 'ok'], true) || $word->is_deferred) {
            return;
        }
        try {
            event(new TokenWordAdded((int) $word->id));
        } catch (Throwable $e) {
            // swallow
        }
    }

    private function findTokenSignature(string $tokenName, string $signature): ?TokenSignature
    {
        return TokenSignature::query()
            ->join('tokens', 'tokens.id', '=', 'token_signatures.token_id')
            ->join('signatures', 'signatures.id', '=', 'token_signatures.signature_id')
            ->where('tokens.name', $tokenName)
            ->where('signatures.signature', $signature)
            ->select('token_signatures.*')
            ->first();
    }
}
