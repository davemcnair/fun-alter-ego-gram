<?php

namespace Tests\Unit;

use App\Models\Target;
use App\Models\Token;
use App\Services\ListPatternsService;
use App\Services\TargetCreationService;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AnagramBehaviorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Minimal tokens
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 1],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => false, 'max_multiples' => 1],
        ]);
        // Empty patterns pipeline
        $mock = Mockery::mock(ListPatternsService::class);
        $mock->shouldReceive('listWithinMinLength')->andReturn(collect());
        $mock->shouldReceive('filterPatternsForTarget')->andReturn(collect());
        $this->app->instance(ListPatternsService::class, $mock);
        // WordMatch minimal
        $wm = Mockery::mock(WordMatchService::class);
        $wm->shouldReceive('storeNewTargetMatchedTokenSignatureWords')->andReturn(collect());
        $wm->shouldReceive('extractMatchingTokenWordMinimumLengths')->andReturn([[], []]);
        $this->app->instance(WordMatchService::class, $wm);
    }

    public function test_anagram_pairs_create_distinct_targets_and_are_discoverable(): void
    {
        $svc = app(TargetCreationService::class);
        $a = $svc->create('Brian James');
        $b = $svc->create('James Brian');

        $this->assertSame(2, Target::count());
        $t1 = $a['target'];
        $t2 = $b['target'];
        $this->assertNotSame($t1->id, $t2->id);
        $this->assertSame('brian james', $t1->normalized_key);
        $this->assertSame('james brian', $t2->normalized_key);
        $this->assertSame($t1->signature, $t2->signature);

        // anagram siblings
        $sib1 = $t1->anagramSiblings();
        $sib2 = $t2->anagramSiblings();
        $this->assertCount(1, $sib1);
        $this->assertCount(1, $sib2);
        $this->assertSame($t2->id, $sib1->first()->id);
        $this->assertSame($t1->id, $sib2->first()->id);
    }

    public function test_invalid_name_rejected(): void
    {
        $this->expectException(HttpException::class);
        app(TargetCreationService::class)->create('!!!');
    }
}
