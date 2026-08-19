<?php

namespace Tests\Unit;

use App\Events\TokenWordAdded;
use App\Models\Token;
use App\Models\TokenSignatureWord;
use App\Services\WordCatalog;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
}
