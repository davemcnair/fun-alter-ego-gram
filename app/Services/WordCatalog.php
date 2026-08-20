<?php

namespace App\Services;

use App\Dtos\SignatureDto;
use App\Dtos\WordCatalogList;
use App\Dtos\WordCatalogQuery;
use App\Dtos\WordCatalogRow;
use App\Events\TokenWordAdded;
use App\Models\Signature;
use App\Models\Token;
use App\Models\TokenSignature;
use App\Models\TokenSignatureWord;
use App\Support\NameNormalizer;
use DateTime;
use Illuminate\Support\Facades\DB;
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

    public function list(WordCatalogQuery $query): WordCatalogList
    {
        $perPage = max(1, $query->perPage);
        $page = max(1, $query->page);

        $builder = TokenSignatureWord::query()
            ->join('token_signatures', 'token_signatures.id', '=', 'token_signature_words.token_signature_id')
            ->join('tokens', 'tokens.id', '=', 'token_signatures.token_id')
            ->select([
                'token_signature_words.id',
                'token_signature_words.word',
                'token_signature_words.list_type',
                'token_signature_words.is_deferred',
                'token_signature_words.committed_at',
                'token_signature_words.updated_at',
                'token_signature_words.token_signature_id',
                'tokens.name as token_type',
            ])
            ->orderBy('token_signature_words.id');

        if ($query->q !== '') {
            if ($query->exact) {
                $builder->where('token_signature_words.word', $query->q);
            } else {
                $builder->where('token_signature_words.word', 'like', '%'.$query->q.'%');
            }
        }
        if ($query->token !== '') {
            $builder->where('tokens.name', $query->token);
        }
        if ($query->list !== '') {
            $builder->where('token_signature_words.list_type', $query->list);
        }
        if ($query->hasAnagrams) {
            $builder->whereExists(function ($inner) {
                $inner->selectRaw('1')
                    ->from('token_signature_words as tsw2')
                    ->whereColumn('tsw2.token_signature_id', 'token_signature_words.token_signature_id')
                    ->whereColumn('tsw2.id', '!=', 'token_signature_words.id');
            });
        }

        $items = $builder->paginate($perPage, ['*'], 'page', $page);

        $anagramsBySignature = $this->anagramsBySignatureId(
            $items->getCollection()->pluck('token_signature_id')->map(fn ($id) => (int) $id)->all()
        );

        $rows = $items->getCollection()->map(function (TokenSignatureWord $word) use ($anagramsBySignature) {
            $signatureId = (int) $word->token_signature_id;
            $siblings = $anagramsBySignature[$signatureId] ?? [];
            $anagrams = array_values(array_filter(
                $siblings,
                fn (array $anagram) => $anagram['id'] !== (int) $word->id
            ));

            return new WordCatalogRow(
                id: (int) $word->id,
                word: (string) $word->word,
                token: (string) $word->token_type,
                list: (string) $word->list_type,
                deferred: (bool) $word->is_deferred,
                uncommitted: $word->committed_at === null || $word->committed_at < $word->updated_at,
                anagrams: $anagrams,
            );
        });
        $items->setCollection($rows);

        $tokenOptions = DB::table('tokens')->orderBy('name')->pluck('name')->all();
        $listOptions = TokenSignatureWord::query()
            ->select('list_type')
            ->distinct()
            ->orderBy('list_type')
            ->pluck('list_type')
            ->all();

        return new WordCatalogList(
            items: $items,
            tokenOptions: array_values($tokenOptions),
            listOptions: array_values($listOptions),
            hasUncommitted: TokenSignatureWord::query()->whereNull('committed_at')->exists(),
        );
    }

    public function replace(TokenSignatureWord $word, string $tokenName, string $newWord, string $listType): TokenSignatureWord
    {
        $created = $this->add($tokenName, $newWord, $listType);
        if ((int) $created->id !== (int) $word->id) {
            $this->delete($word);
        }

        return $created;
    }

    public function delete(TokenSignatureWord $word): void
    {
        $signatureId = (int) $word->token_signature_id;
        $wasRepresentative = ! $word->is_deferred;
        $word->delete();

        if (! $wasRepresentative) {
            return;
        }

        $siblings = TokenSignatureWord::query()
            ->where('token_signature_id', $signatureId)
            ->orderBy('id')
            ->get();
        if ($siblings->isEmpty() || $siblings->contains(fn (TokenSignatureWord $sibling) => ! $sibling->is_deferred)) {
            return;
        }

        $fun = $siblings->first(fn (TokenSignatureWord $sibling) => strtolower((string) $sibling->list_type) === 'fun');
        $next = $fun ?? $siblings->first();
        if (! $next) {
            return;
        }

        $next->is_deferred = false;
        $next->save();
        $this->notifyIfSearchable($next, null);
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

    /**
     * @param list<int> $signatureIds
     * @return array<int, list<array{id: int, word: string}>>
     */
    private function anagramsBySignatureId(array $signatureIds): array
    {
        $ids = array_values(array_unique(array_filter($signatureIds)));
        if ($ids === []) {
            return [];
        }

        $rows = TokenSignatureWord::query()
            ->whereIn('token_signature_id', $ids)
            ->orderBy('word')
            ->get(['id', 'word', 'token_signature_id']);

        $bySignature = [];
        foreach ($rows as $row) {
            $bySignature[(int) $row->token_signature_id][] = [
                'id' => (int) $row->id,
                'word' => (string) $row->word,
            ];
        }

        return $bySignature;
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
