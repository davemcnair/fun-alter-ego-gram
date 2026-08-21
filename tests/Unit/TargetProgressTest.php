<?php

namespace Tests\Unit;

use App\Dtos\SignatureDto;
use App\Dtos\TargetProgressQuery;
use App\Dtos\TargetProgressRow;
use App\Enums\TargetPatternStatus;
use App\Enums\TargetStatus;
use App\Models\AlterEgo;
use App\Models\Pattern;
use App\Models\Signature;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Models\TargetSignaturedPattern;
use App\Models\TargetTokenSignature;
use App\Models\Token;
use App\Models\TokenSignature;
use App\Services\TargetProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Request;
use Tests\TestCase;

class TargetProgressTest extends TestCase
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

    public function test_list_returns_snapshot_rows_newest_first(): void
    {
        $older = $this->makeTarget('Ada');
        $newer = $this->makeTarget('Jane');
        $this->attachPattern($older, '{forename}', TargetPatternStatus::FILLED);
        $this->attachPattern($older, '{surname}', TargetPatternStatus::PENDING);
        $this->attachAlterEgo($this->attachPattern($newer, '{forename}{surname}', TargetPatternStatus::FILLED), 'Jane Ray');

        $snapshot = app(TargetProgress::class)->list(new TargetProgressQuery());

        $this->assertCount(2, $snapshot->items);
        $this->assertInstanceOf(TargetProgressRow::class, $snapshot->items->first());
        $this->assertSame([$newer->id, $older->id], $snapshot->items->pluck('id')->all());

        $jane = $snapshot->items->first();
        $this->assertSame('Jane', $jane->name);
        $this->assertSame(1, $jane->patternsFilled);
        $this->assertSame(1, $jane->patternsTotal);
        $this->assertSame(1, $jane->alterEgosCount);
        $this->assertSame(0, $jane->filledMatches);
        $this->assertSame(0, $jane->newMatches);
        $this->assertNull($jane->lastProcessed);
        $this->assertSame(0, $jane->unseenMatches);

        $ada = $snapshot->items->last();
        $this->assertSame('Ada', $ada->name);
        $this->assertSame(1, $ada->patternsFilled);
        $this->assertSame(2, $ada->patternsTotal);
        $this->assertSame(0, $ada->alterEgosCount);
    }

    public function test_list_keeps_both_match_watermarks(): void
    {
        $target = $this->makeTarget('Jane');
        $target->last_processed_matches_at = '2026-01-01 12:00:00';
        $target->matches_seen_at = '2026-01-01 12:30:00';
        $target->save();

        $this->addMatch($target, 'jane', '2026-01-01 11:00:00');
        $this->addMatch($target, 'ray', '2026-01-01 13:00:00');

        $row = app(TargetProgress::class)->list(new TargetProgressQuery())->items->first();
        $this->assertSame(1, $row->filledMatches);
        $this->assertSame(1, $row->newMatches);
        $this->assertSame('2026-01-01 12:00:00', $row->lastProcessed);
        $this->assertSame(1, $row->unseenMatches);

        $target->matches_seen_at = null;
        $target->save();

        $unseenAll = app(TargetProgress::class)->list(new TargetProgressQuery())->items->first();
        $this->assertSame(2, $unseenAll->unseenMatches);
    }

    public function test_list_uses_explicit_page_and_clamps(): void
    {
        $this->makeTarget('Ada');
        $middle = $this->makeTarget('Bea');
        $this->makeTarget('Cara');

        Request::merge(['page' => 99]);

        $pageTwo = app(TargetProgress::class)->list(new TargetProgressQuery(perPage: 1, page: 2));
        $this->assertSame(2, $pageTwo->items->currentPage());
        $this->assertCount(1, $pageTwo->items);
        $this->assertSame($middle->id, $pageTwo->items->first()->id);

        $clamped = app(TargetProgress::class)->list(new TargetProgressQuery(perPage: 0, page: 0));
        $this->assertSame(1, $clamped->items->perPage());
        $this->assertSame(1, $clamped->items->currentPage());
    }

    public function test_set_starred_updates_all_matching_phrases_for_the_target(): void
    {
        $target = $this->makeTarget('Jane');
        $other = $this->makeTarget('Ada');
        $first = $this->attachAlterEgo($this->attachPattern($target, '{forename}', TargetPatternStatus::FILLED), 'Jane Ray');
        $second = $this->attachAlterEgo($this->attachPattern($target, '{surname}', TargetPatternStatus::FILLED), 'Jane Ray');
        $otherEgo = $this->attachAlterEgo($this->attachPattern($other, '{forename}', TargetPatternStatus::FILLED), 'Jane Ray');

        $progress = app(TargetProgress::class);
        $starred = $progress->setStarred($target, 'Jane Ray', true);

        $this->assertTrue((bool) $first->fresh()->starred);
        $this->assertTrue((bool) $second->fresh()->starred);
        $this->assertFalse((bool) $otherEgo->fresh()->starred);
        $this->assertEqualsCanonicalizing(['Jane Ray', 'Jane Ray'], $starred);

        $noop = $progress->setStarred($target, 'Missing', false);
        $this->assertEqualsCanonicalizing(['Jane Ray', 'Jane Ray'], $noop);
        $this->assertTrue((bool) $first->fresh()->starred);

        $cleared = $progress->setStarred($target, 'Jane Ray', false);
        $this->assertSame([], $cleared);
        $this->assertFalse((bool) $first->fresh()->starred);
    }

    private function makeTarget(string $name): Target
    {
        $dto = SignatureDto::fromWord($name);
        $sig = Signature::firstOrCreate(['signature' => $dto->signature], $dto->defaults);

        return Target::create([
            'name' => $name,
            'signature_id' => $sig->id,
            'normalized_key' => strtolower(str_replace(' ', '', $name)),
            'status' => TargetStatus::filterable,
        ]);
    }

    private function attachPattern(Target $target, string $template, TargetPatternStatus $status): TargetPattern
    {
        $pattern = Pattern::query()->firstOrCreate(
            ['template' => $template],
            [
                'popularity_rank' => 1,
                'pattern_type' => 'standard',
                'min_total_length' => 2,
                'forename_count' => 1,
                'surname_count' => 0,
            ]
        );

        return TargetPattern::create([
            'target_id' => $target->id,
            'pattern_id' => $pattern->id,
            'popularity_rank' => 1,
            'status' => $status,
        ]);
    }

    private function attachAlterEgo(TargetPattern $targetPattern, string $phrase): AlterEgo
    {
        $signatured = TargetSignaturedPattern::create([
            'target_pattern_id' => $targetPattern->id,
        ]);

        return AlterEgo::create([
            'target_signatured_pattern_id' => $signatured->id,
            'phrase' => $phrase,
            'starred' => false,
        ]);
    }

    private function addMatch(Target $target, string $word, string $createdAt): TargetTokenSignature
    {
        $dto = SignatureDto::fromWord($word);
        $sig = Signature::firstOrCreate(['signature' => $dto->signature], $dto->defaults);
        $token = Token::query()->where('name', 'forename')->firstOrFail();
        $tokenSignature = TokenSignature::firstOrCreate([
            'token_id' => $token->id,
            'signature_id' => $sig->id,
        ]);

        $row = TargetTokenSignature::create([
            'target_id' => $target->id,
            'token_signature_id' => $tokenSignature->id,
        ]);
        $row->created_at = $createdAt;
        $row->save();

        return $row;
    }
}
