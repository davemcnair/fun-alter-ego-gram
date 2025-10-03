<?php

namespace Tests\Unit;

use App\Dtos\WordDto;
use App\Services\PhraseBuilderService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PhraseBuilderServiceTest extends TestCase
{
    public const FUN = true;
    public const BORING = true;

    public static function providePhraseSlots(): array
    {
        return [
            'capitalize_single_surname' => [
                [
                    0 => new WordDto('title', 'Dr', 'ok'),
                    1 => new WordDto('forename', 'Adam', 'ok'),
                    2 => new WordDto('surname', 'vinci', 'ok'),
                ],
                'Dr Adam Vinci', !static::FUN, !static::BORING
            ],
            'capitalize_hyphenate_double_surname_fun' => [
                [
                    0 => new WordDto('title', 'Dr', 'ok'),
                    1 => new WordDto('forename', 'Dan', 'ok'),
                    2 => new WordDto('surname', 'dim', 'fun'),
                    3 => new WordDto('surname', 'vinci', 'ok'),
                ],
                'Dr Dan Dim-Vinci', static::FUN, !static::BORING
            ],
        ];
    }

    #[Test]
    #[DataProvider('providePhraseSlots')]
    public function it_builds_phrase(array $slots, string $phrase, bool $isFun, bool $hasBoring): void
    {
        $svc = new PhraseBuilderService();
        $actual = $svc->formatPhraseBySlots($slots);
        $this->assertSame($phrase, $actual->phrase);
        $this->assertEquals($isFun, $actual->isFun);
        $this->assertEquals($hasBoring, $actual->hasBoring);
    }

//    public function test_double_surname_lists_both_variants_for_display(): void
//    {
//        $svc = new PhraseBuilderService();
//        $words = ['Vicar', 'Dan', 'dim', 'vinci'];
//        $patternTokenPositions = [
//            ['name' => 'title', 'pos' => 0],
//            ['name' => 'forename', 'pos' => 1],
//            ['name' => 'surname', 'pos' => 2],
//            ['name' => 'surname', 'pos' => 3],
//        ];
//        $phrase = $svc->formatPhraseBySlots($words, $patternTokenPositions, true);
//        $this->assertSame('Vicar Dan Dim-Vinci, Vinci-Dim', $phrase);
//    }
//
//    public function test_triple_surname_does_not_list_variants_for_display(): void
//    {
//        $svc = new PhraseBuilderService();
//        $words = ['Sir', 'adam', 'dim', 'vinci', 'mongrel'];
//        $patternTokenPositions = [
//            ['name' => 'title', 'pos' => 0],
//            ['name' => 'forename', 'pos' => 1],
//            ['name' => 'surname', 'pos' => 2],
//            ['name' => 'surname', 'pos' => 3],
//            ['name' => 'surname', 'pos' => 4],
//        ];
//        $phrase = $svc->formatPhraseBySlots($words, $patternTokenPositions, true);
//        $this->assertSame('Sir Adam Dim-Vinci-Mongrel', $phrase);
//    }
}
