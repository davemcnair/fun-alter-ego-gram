<?php

namespace Tests\Unit;

use App\Events\TokenWordAdded;
use App\Models\Token;
use App\Models\TokenSignatureWord;
use App\Services\WordMatchService;
use App\Services\WordStoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WordStoreServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Minimal token seed used by services
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 1],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => false, 'max_multiples' => 1],
        ]);
    }

    public function test_get_existing_anagrams_returns_list_and_selected_id(): void
    {
        $wm = app(WordMatchService::class);
        // Create two anagrams under same signature for forename: 'Adam' (aadm) and 'Dama' (aadm)
        $w1 = $wm->addTokenWord('forename', 'Dama', 'ok'); // first under new signature: not deferred
        $w2 = $wm->addTokenWord('forename', 'Adam', 'fun'); // fun exists, retro-defer first; fun becomes active

        $this->assertNotNull($w1);
        $this->assertNotNull($w2);

        $sig = app(WordMatchService::class)->makeSignature('Adam');
        $svc = app(WordStoreService::class);
        [$existing, $selectedId] = $svc->getExistingAnagrams('forename', $sig);

        $this->assertGreaterThanOrEqual(2, $existing->count());
        $this->assertSame($w2->id, $selectedId, 'Selected should be the non-deferred (fun) representative');
        // Ensure synthesized use_for_search is present and accurate
        $active = $existing->firstWhere('id', $selectedId);
        $this->assertTrue((bool)($active->use_for_search ?? false));
    }

    public function test_createNewWordAndMaybeDispatch_emits_for_fun_and_skips_when_deferred_or_boring(): void
    {
        Event::fake();
        $svc = app(WordStoreService::class);

        // Emits for FUN when not deferred
        $createdFun = $svc->createNewWordAndMaybeDispatch('surname', 'ray', 'fun');
        $this->assertNotNull($createdFun);
        Event::assertDispatched(TokenWordAdded::class, function($e) use ($createdFun){
            return (int)$e->tokenSignatureWordId === (int)$createdFun->id;
        });

        Event::fake();
        // Prepare existing signature so subsequent OK becomes deferred
        $wm = app(WordMatchService::class);
        $wm->addTokenWord('surname', 'yra', 'fun'); // same signature as 'ray'

        // Adding OK for existing signature should be deferred => no event
        $createdOkDeferred = $svc->createNewWordAndMaybeDispatch('surname', 'ary', 'ok');
        $this->assertNotNull($createdOkDeferred);
        $this->assertTrue((bool)$createdOkDeferred->is_deferred);
        Event::assertNotDispatched(TokenWordAdded::class);

        Event::fake();
        // Boring should never emit
        $boring = $svc->createNewWordAndMaybeDispatch('surname', 'ravy', 'boring');
        $this->assertNotNull($boring);
        Event::assertNotDispatched(TokenWordAdded::class);
    }

    public function test_designateRepresentativeAndMaybeDispatch_sets_rep_and_emits_when_created_selected(): void
    {
        Event::fake();
        $wm = app(WordMatchService::class);
        $svc = app(WordStoreService::class);

        // Set up an existing signature with two OK words (first is active, second deferred)
        $w1 = $wm->addTokenWord('forename', 'Dama', 'ok');
        $w2 = $wm->addTokenWord('forename', 'Amad', 'ok');
        $this->assertFalse((bool)$w1->is_deferred);
        $this->assertTrue((bool)$w2->is_deferred);

        // Create a FUN candidate in the same signature
        $createdFun = $wm->addTokenWord('forename', 'Adam', 'fun');
        $this->assertNotNull($createdFun);

        // Choose the newly created FUN as representative
        $sig = app(WordMatchService::class)->makeSignature('Adam');
        $finalId = $svc->designateRepresentativeAndMaybeDispatch('forename', $sig, null, $createdFun);

        $this->assertSame($createdFun->id, $finalId);
        // Event should be dispatched when created becomes the representative and is eligible
        Event::assertDispatched(TokenWordAdded::class, function($e) use ($createdFun){
            return (int)$e->tokenSignatureWordId === (int)$createdFun->id;
        });

        // DB state: created is non-deferred; others are deferred
        $freshCreated = TokenSignatureWord::find($createdFun->id);
        $this->assertFalse((bool)$freshCreated->is_deferred);
        $this->assertTrue((bool)TokenSignatureWord::find($w1->id)->is_deferred);
        $this->assertTrue((bool)TokenSignatureWord::find($w2->id)->is_deferred);
    }

    public function test_designateRepresentativeAndMaybeDispatch_selects_existing_without_emitting(): void
    {
        Event::fake();
        $wm = app(WordMatchService::class);
        $svc = app(WordStoreService::class);

        $w1 = $wm->addTokenWord('surname', 'ray', 'ok');
        $w2 = $wm->addTokenWord('surname', 'ary', 'ok');
        $sig = app(WordMatchService::class)->makeSignature('ray');

        // Select existing as representative, without a newly created candidate
        $finalId = $svc->designateRepresentativeAndMaybeDispatch('surname', $sig, $w2->id, null);
        $this->assertSame($w2->id, $finalId);

        // No event should be emitted since the created word did not become representative
        Event::assertNotDispatched(TokenWordAdded::class);

        $this->assertFalse((bool)TokenSignatureWord::find($w2->id)->is_deferred);
        $this->assertTrue((bool)TokenSignatureWord::find($w1->id)->is_deferred);
    }
}
