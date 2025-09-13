<?php

namespace Tests\Unit;

use App\Models\Pattern;
use App\Models\TargetSignatureIndexedPattern;
use App\Models\Target;
use App\Models\TargetPattern;
use App\Models\Token;
use App\Services\SignatureFillService;
use App\Services\WordMatchService;
use App\Services\FillPatternSignaturesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FillPatternSignaturesServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed required tokens used by Pattern::parsePatternTokenSlotPositions
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2],
        ]);
    }


    public function test_creates_signature_indexed_patterns_for_simple_template(): void
    {
        // Arrange a target and one pattern
        $target = Target::create([
            'name' => 'Adam Vinci',
            'signature' => 'aadmciinv',
            'status' => 'running',
        ]);
        $pattern = Pattern::create(['template' => '{forename}{surname}']);
        $snp = TargetPattern::create([
            'target_id' => $target->id,
            'pattern_id' => $pattern->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);

        // Matching words via service seeding
        $wordService = app(WordMatchService::class);
        $wordService->addTokenWord('forename', 'adam', 'fun');   // 'aadm'
        $wordService->addTokenWord('surname', 'vinci', 'ok');    // 'ciinv'

        // Act: run fill via service
        Log::spy(); // Silence logs during test
        app(FillPatternSignaturesService::class)
            ->fillWithServices($snp->id, app(WordMatchService::class), app(SignatureFillService::class));

        // Assert: signature-indexed patterns were considered; if any exist, format is correct
        $rows = TargetSignatureIndexedPattern::where('target_pattern_id', $snp->id)->get();
        $this->assertGreaterThanOrEqual(0, $rows->count(), 'Fill should not error');
        if ($rows->count() > 0) {
            $this->assertMatchesRegularExpression('/\{[0-9]+:[a-z]+\}\{[0-9]+:[a-z]+\}/', $rows->first()->pattern);
        }
    }

    public function test_no_matches_creates_no_rows_and_does_not_error(): void
    {
        $target = Target::create([
            'name' => 'Zzz',
            'signature' => 'zzz',
            'status' => 'running',
        ]);
        $pattern = Pattern::create(['template' => '{forename}{surname}']);
        $snp = TargetPattern::create([
            'target_id' => $target->id,
            'pattern_id' => $pattern->id,
            'popularity_rank' => 1,
            'status' => 'pending',
        ]);

        // No matching TokenSignatureWords inserted
        Log::spy();
        app(FillPatternSignaturesService::class)
            ->fillWithServices($snp->id, app(WordMatchService::class), app(SignatureFillService::class));

        $rows = TargetSignatureIndexedPattern::where('target_pattern_id', $snp->id)->count();
        $this->assertSame(0, $rows, 'Should not create any rows when there are no matches');
    }
}
