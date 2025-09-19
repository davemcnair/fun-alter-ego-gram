<?php

namespace Tests\Unit;

use App\Support\NameNormalizer;
use PHPUnit\Framework\TestCase;

class NameNormalizerTest extends TestCase
{
    public function test_accents_are_folded_and_punctuation_preserved_in_display(): void
    {
        $input = 'José Álvarez';
        $this->assertSame('jose alvarez', NameNormalizer::canonicalKey($input));
        $this->assertSame('aaeejlorsvz', NameNormalizer::anagramSignature($input)->signature);
        $this->assertSame('José Álvarez', NameNormalizer::displayName($input));
    }

    public function test_punctuation_signature_and_display(): void
    {
        $input = "O’Connor-Smith"; // curly apostrophe
        $this->assertSame('oconnor smith', NameNormalizer::canonicalKey($input));
        $this->assertSame('chimnnooorst', NameNormalizer::anagramSignature($input)->signature);
        $this->assertSame("O’Connor-Smith", NameNormalizer::displayName($input));
    }
}
