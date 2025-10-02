<?php

namespace Tests\Unit;

use App\Traits\HelpsMatchWords;
use PHPUnit\Framework\TestCase;

class HelpsMatchWordsTest extends TestCase
{
    private $helper;

    protected function setUp(): void
    {
        parent::setUp();
        // anonymous class using the trait for testing
        $this->helper = new class {
            use HelpsMatchWords;
        };
    }

    public function test_normalize_removes_non_letters_and_lowercases(): void
    {
        $this->assertSame('davidmcnair', $this->helper->normalize("David McNair!"));
        $this->assertSame('hello', $this->helper->normalize('HeLLo'));
        $this->assertSame('', $this->helper->normalize("1234!?"));
    }

    public function test_make_signature_sorts_letters(): void
    {
        $this->assertSame('aacddiimnrv', $this->helper->makeSignature('David McNair'));
        $this->assertSame('aaabnn', $this->helper->makeSignature('Banana'));
        $this->assertSame('', $this->helper->makeSignature('---')); // no letters
    }

    public function test_is_subset_true_when_small_multiset_is_contained(): void
    {
        $big = $this->helper->makeSignature('David McNair');
        $small = $this->helper->makeSignature('Dr');
        $this->assertTrue($this->helper->isSubset($small, $big));
    }

    public function test_is_subset_false_when_not_contained(): void
    {
        $big = $this->helper->makeSignature('David McNair');
        $small = $this->helper->makeSignature('zzz');
        $this->assertFalse($this->helper->isSubset($small, $big));
    }

    public function test_letter_counts_and_subtract(): void
    {
        $sig = $this->helper->makeSignature('Vinci'); // ciinv
        // compute letterCounts locally (no trait helper)
        $counts = [];
        for ($i=0, $len=strlen($sig); $i<$len; $i++) {
            $ch = $sig[$i];
            $counts[$ch] = ($counts[$ch] ?? 0) + 1;
        }
        $this->assertSame(['c'=>1,'i'=>2,'n'=>1,'v'=>1], $counts);
        $need = ['a'=>1,'c'=>1,'i'=>2,'n'=>1,'v'=>1];
        $remaining = $this->helper->subtract($need, $counts);
        $this->assertSame(['a'=>1], $remaining);
    }

    public function test_candidate_letters_exceed_needed_counts(): void
    {
        $need = ['a'=>1,'d'=>1,'n'=>1];
        $candOk = ['a'=>1];
        $candBad = ['a'=>2];
        $this->assertFalse($this->helper->candidateLettersExceedNeededCounts($need, $candOk));
        $this->assertTrue($this->helper->candidateLettersExceedNeededCounts($need, $candBad));
    }

    public function test_union_can_fill_slot_aware_upper_bound(): void
    {
        // token precomputed maxes (e.g., from DfsService/SignatureFillService)
        $tokenPrecomputed = [
            'forename' => ['maxLetterCounts' => ['a'=>2,'d'=>1,'n'=>1]],
            'surname'  => ['maxLetterCounts' => ['c'=>1,'i'=>2,'n'=>1,'v'=>1]],
        ];
        $need = ['a'=>1,'c'=>1,'i'=>2,'n'=>1,'v'=>1]; // Adam + Vinci
        $this->assertTrue($this->helper->canAssembleFromTokens($tokenPrecomputed, $need));
        $needTooMuch = ['a'=>3,'c'=>1,'i'=>2,'n'=>1,'v'=>1];
        $this->assertFalse($this->helper->canAssembleFromTokens($tokenPrecomputed, $needTooMuch));
    }
}
