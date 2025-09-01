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
}
