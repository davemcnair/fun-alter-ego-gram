<?php

namespace Tests\Feature;

use App\Dtos\SignatureDto;
use App\Enums\TargetPatternStatus;
use App\Enums\TargetStatus;
use App\Models\AlterEgo;
use App\Models\Pattern;
use App\Models\Signature;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Models\TargetSignaturedPattern;
use App\Models\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TargetIndexPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 1],
            ['name' => 'surname', 'prio' => 2, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => false, 'max_multiples' => 1],
        ]);
    }

    public function test_index_renders_snapshot_and_star_updates_phrases(): void
    {
        $dto = SignatureDto::fromWord('Jane');
        $sig = Signature::firstOrCreate(['signature' => $dto->signature], $dto->defaults);
        $target = Target::create([
            'name' => 'Jane',
            'signature_id' => $sig->id,
            'normalized_key' => 'jane',
            'status' => TargetStatus::filterable,
        ]);
        $pattern = Pattern::create([
            'template' => '{forename}',
            'popularity_rank' => 1,
            'pattern_type' => 'standard',
            'min_total_length' => 2,
            'forename_count' => 1,
            'surname_count' => 0,
        ]);
        $targetPattern = TargetPattern::create([
            'target_id' => $target->id,
            'pattern_id' => $pattern->id,
            'popularity_rank' => 1,
            'status' => TargetPatternStatus::FILLED,
        ]);
        $signatured = TargetSignaturedPattern::create([
            'target_pattern_id' => $targetPattern->id,
        ]);
        AlterEgo::create([
            'target_signatured_pattern_id' => $signatured->id,
            'phrase' => 'Jane Ray',
            'starred' => false,
        ]);

        $this->get(route('targets.index'))
            ->assertOk()
            ->assertSee('Jane')
            ->assertSee('1 / 1')
            ->assertSee('0(0)');

        $this->postJson(route('api.targets.star', $target), ['phrase' => 'Jane Ray'])
            ->assertOk()
            ->assertJson(['ok' => true, 'starred' => ['Jane Ray']]);

        $this->assertTrue((bool) AlterEgo::query()->first()->starred);

        $this->postJson(route('api.targets.unstar', $target), ['phrase' => 'Jane Ray'])
            ->assertOk()
            ->assertJson(['ok' => true, 'starred' => []]);
    }
}
