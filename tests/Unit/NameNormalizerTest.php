<?php

namespace Tests\Unit;

use App\Support\NameNormalizer;
use PHPUnit\Framework\TestCase;

class NameNormalizerTest extends TestCase
{
    public function test_accents_are_folded_and_punctuation_preserved_in_display(): void
    {
        $input = 'José Álvarez';
        $this->assertSame('josealvarez', NameNormalizer::canonicalKey($input));
        $this->assertSame('José Álvarez', NameNormalizer::displayName($input));
    }

    public function test_punctuation_signature_and_display(): void
    {
        $input = "O’Connor-Smith"; // curly apostrophe
        $this->assertSame('oconnorsmith', NameNormalizer::canonicalKey($input));
        $this->assertSame("O’Connor-Smith", NameNormalizer::displayName($input));
    }
}
