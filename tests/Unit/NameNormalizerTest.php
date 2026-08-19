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
        $this->assertSame('josealvarez', NameNormalizer::letterString($input));
        $this->assertSame(
            NameNormalizer::anagramSignature('Jose Alvarez')->signature,
            NameNormalizer::anagramSignature($input)->signature
        );
        $this->assertSame('José Álvarez', NameNormalizer::displayName($input));
    }

    public function test_punctuation_signature_and_display(): void
    {
        $input = "O’Connor-Smith"; // curly apostrophe
        $this->assertSame('oconnor smith', NameNormalizer::canonicalKey($input));
        $this->assertSame('chimnnooorst', NameNormalizer::anagramSignature($input)->signature);
        $this->assertSame("O’Connor-Smith", NameNormalizer::displayName($input));
    }

    public function test_letter_string_and_signature_drop_digits_and_sort(): void
    {
        $this->assertSame('davidmcnair', NameNormalizer::letterString('David McNair!'));
        $this->assertSame('aacddiimnrv', NameNormalizer::anagramSignature('David McNair')->signature);
        $this->assertSame('', NameNormalizer::letterString('1234!?'));
        $this->assertSame('', NameNormalizer::anagramSignature('1234')->signature);
        $this->assertSame('aaabnn', NameNormalizer::anagramSignature('Banana')->signature);
    }
}
