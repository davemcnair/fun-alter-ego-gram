<?php

namespace Tests\Unit;

use App\Dtos\WordCatalogQuery;
use App\Dtos\WordCatalogRow;
use App\Events\TokenWordAdded;
use App\Models\Token;
use App\Models\TokenSignatureWord;
use App\Services\WordCatalog;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Request;
use Tests\TestCase;

class WordCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 1],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => false, 'max_multiples' => 1],
        ]);
    }

    public function test_existing_anagrams_returns_list_and_selected_id(): void
    {
        $catalog = app(WordCatalog::class);
        $catalog->add('forename', 'Dama', 'ok');
        $fun = $catalog->add('forename', 'Adam', 'fun');

        $sig = NameNormalizer::anagramSignature('Adam')->signature;
        [$existing, $selectedId] = $catalog->existingAnagrams('forename', $sig);

        $this->assertGreaterThanOrEqual(2, $existing->count());
        $this->assertSame($fun->id, $selectedId);
    }

    public function test_add_emits_for_fun_and_skips_deferred_ok_and_boring(): void
    {
        Event::fake();
        $catalog = app(WordCatalog::class);

        $createdFun = $catalog->add('surname', 'ray', 'fun');
        Event::assertDispatched(TokenWordAdded::class, function ($e) use ($createdFun) {
            return (int) $e->tokenSignatureWordId === (int) $createdFun->id;
        });

        Event::fake();
        $catalog->add('surname', 'yra', 'fun');
        Event::fake();
        $createdOkDeferred = $catalog->add('surname', 'ary', 'ok');
        $this->assertTrue((bool) $createdOkDeferred->is_deferred);
        Event::assertNotDispatched(TokenWordAdded::class);

        Event::fake();
        $catalog->add('surname', 'ravy', 'boring');
        Event::assertNotDispatched(TokenWordAdded::class);
    }

    public function test_choose_representative_selects_created_fun(): void
    {
        Event::fake();
        $catalog = app(WordCatalog::class);

        $w1 = $catalog->add('forename', 'Dama', 'ok');
        $w2 = $catalog->add('forename', 'Amad', 'ok');
        $createdFun = $catalog->add('forename', 'Adam', 'fun');

        $sig = NameNormalizer::anagramSignature('Adam')->signature;
        $finalId = $catalog->chooseRepresentative('forename', $sig, null, $createdFun);

        $this->assertSame($createdFun->id, $finalId);
        $this->assertFalse((bool) TokenSignatureWord::find($createdFun->id)->is_deferred);
        $this->assertTrue((bool) TokenSignatureWord::find($w1->id)->is_deferred);
        $this->assertTrue((bool) TokenSignatureWord::find($w2->id)->is_deferred);
    }

    public function test_choose_representative_selects_existing_without_emitting(): void
    {
        Event::fake();
        $catalog = app(WordCatalog::class);

        $w1 = $catalog->add('surname', 'ray', 'ok');
        $w2 = $catalog->add('surname', 'ary', 'ok');
        $sig = NameNormalizer::anagramSignature('ray')->signature;

        $finalId = $catalog->chooseRepresentative('surname', $sig, $w2->id, null);
        $this->assertSame($w2->id, $finalId);
        Event::assertNotDispatched(TokenWordAdded::class);
        $this->assertFalse((bool) TokenSignatureWord::find($w2->id)->is_deferred);
        $this->assertTrue((bool) TokenSignatureWord::find($w1->id)->is_deferred);
    }

    public function test_promote_fun_backfills_demote_does_not(): void
    {
        Event::fake();
        $catalog = app(WordCatalog::class);
        $ok = $catalog->add('surname', 'kinky', 'ok');

        Event::fake();
        $result = $catalog->promote($ok->fresh());
        $this->assertTrue($result['ok']);
        $this->assertSame('fun', $ok->fresh()->list_type);
        Event::assertDispatched(TokenWordAdded::class);

        Event::fake();
        $result = $catalog->demote($ok->fresh());
        $this->assertTrue($result['ok']);
        $this->assertSame('boring', $ok->fresh()->list_type);
        Event::assertNotDispatched(TokenWordAdded::class);
    }

    public function test_import_committed_add_does_not_emit(): void
    {
        Event::fake();
        app(WordCatalog::class)->add('forename', 'adam', 'fun', now());
        Event::assertNotDispatched(TokenWordAdded::class);
    }

    public function test_list_returns_snapshot_with_anagrams_and_uncommitted(): void
    {
        $catalog = app(WordCatalog::class);
        $ok = $catalog->add('forename', 'dama', 'ok');
        $fun = $catalog->add('forename', 'adam', 'fun');
        $catalog->add('surname', 'ray', 'ok');

        $snapshot = $catalog->list(new WordCatalogQuery());

        $this->assertTrue($snapshot->hasUncommitted);
        $this->assertSame(['forename', 'surname'], $snapshot->tokenOptions);
        $this->assertSame(['fun', 'ok'], $snapshot->listOptions);
        $this->assertCount(3, $snapshot->items);

        $adam = $snapshot->items->first(fn (WordCatalogRow $row) => $row->id === $fun->id);
        $this->assertNotNull($adam);
        $this->assertSame('adam', $adam->word);
        $this->assertSame('forename', $adam->token);
        $this->assertSame('fun', $adam->list);
        $this->assertFalse($adam->deferred);
        $this->assertTrue($adam->uncommitted);
        $this->assertSame([['id' => $ok->id, 'word' => 'dama']], $adam->anagrams);

        $committed = $catalog->add('surname', 'brown', 'boring', now());
        $committed->committed_at = now();
        $committed->updated_at = now();
        $committed->save();

        $snapshot = $catalog->list(new WordCatalogQuery(q: 'brown', exact: true));
        $this->assertCount(1, $snapshot->items);
        $this->assertFalse($snapshot->items->first()->uncommitted);
        $this->assertTrue($snapshot->hasUncommitted);
    }

    public function test_list_filters_and_uses_explicit_page(): void
    {
        $catalog = app(WordCatalog::class);
        $catalog->add('forename', 'adam', 'fun');
        $catalog->add('forename', 'dama', 'ok');
        $lone = $catalog->add('surname', 'kinky', 'ok');

        Request::merge(['page' => 99]);

        $pageTwo = $catalog->list(new WordCatalogQuery(token: 'forename', perPage: 1, page: 2));
        $this->assertSame(2, $pageTwo->items->currentPage());
        $this->assertCount(1, $pageTwo->items);
        $this->assertSame('dama', $pageTwo->items->first()->word);

        $anagramsOnly = $catalog->list(new WordCatalogQuery(hasAnagrams: true));
        $this->assertEqualsCanonicalizing(['adam', 'dama'], $anagramsOnly->items->pluck('word')->all());

        $surnames = $catalog->list(new WordCatalogQuery(token: 'surname', list: 'ok'));
        $this->assertSame([$lone->id], $surnames->items->pluck('id')->all());

        $clamped = $catalog->list(new WordCatalogQuery(perPage: 0, page: 0));
        $this->assertSame(1, $clamped->items->perPage());
        $this->assertSame(1, $clamped->items->currentPage());
    }

    public function test_delete_live_word_prefers_fun_sibling_and_emits(): void
    {
        Event::fake();
        $catalog = app(WordCatalog::class);
        $ok = $catalog->add('forename', 'dama', 'ok');
        $fun = $catalog->add('forename', 'adam', 'fun');
        $sig = NameNormalizer::anagramSignature('adam')->signature;
        $catalog->chooseRepresentative('forename', $sig, $ok->id, null);

        Event::fake();
        $catalog->delete($ok->fresh());

        $this->assertNull(TokenSignatureWord::find($ok->id));
        $this->assertFalse((bool) $fun->fresh()->is_deferred);
        Event::assertDispatched(TokenWordAdded::class, function ($e) use ($fun) {
            return (int) $e->tokenSignatureWordId === (int) $fun->id;
        });
    }

    public function test_delete_live_word_falls_back_to_oldest_sibling(): void
    {
        Event::fake();
        $catalog = app(WordCatalog::class);
        $older = $catalog->add('surname', 'ray', 'ok');
        $newer = $catalog->add('surname', 'ary', 'ok');
        $sig = NameNormalizer::anagramSignature('ray')->signature;
        $catalog->chooseRepresentative('surname', $sig, $newer->id, null);

        Event::fake();
        $catalog->delete($newer->fresh());

        $this->assertFalse((bool) $older->fresh()->is_deferred);
        Event::assertDispatched(TokenWordAdded::class, function ($e) use ($older) {
            return (int) $e->tokenSignatureWordId === (int) $older->id;
        });
    }

    public function test_delete_last_word_and_non_representative_do_not_undefer(): void
    {
        Event::fake();
        $catalog = app(WordCatalog::class);
        $only = $catalog->add('forename', 'jane', 'fun');
        $catalog->delete($only);
        $this->assertSame(0, TokenSignatureWord::count());

        $ok = $catalog->add('forename', 'dama', 'ok');
        $fun = $catalog->add('forename', 'adam', 'fun');
        Event::fake();
        $catalog->delete($ok->fresh());

        $this->assertFalse((bool) $fun->fresh()->is_deferred);
        Event::assertNotDispatched(TokenWordAdded::class);
    }

    public function test_replace_uses_delete_to_keep_a_representative(): void
    {
        Event::fake();
        $catalog = app(WordCatalog::class);
        $ok = $catalog->add('surname', 'ray', 'ok');
        $fun = $catalog->add('surname', 'ary', 'fun');

        Event::fake();
        $catalog->replace($fun->fresh(), 'surname', 'brown', 'ok');

        $this->assertNull(TokenSignatureWord::find($fun->id));
        $this->assertFalse((bool) $ok->fresh()->is_deferred);
        Event::assertDispatched(TokenWordAdded::class, function ($e) use ($ok) {
            return (int) $e->tokenSignatureWordId === (int) $ok->id;
        });
    }
}
