<?php

namespace Tests\Unit;

use App\Dtos\PhraseDto;
use App\Dtos\WordDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PhraseDtoTest extends TestCase
{
    public static function providePhrases(): array
    {
        return [
            'title_forename_surname' => [
                [
                    new WordDto('title', 'Dr', 'ok'),
                    new WordDto('forename', 'adam', 'ok'),
                    new WordDto('surname', 'vinci', 'ok'),
                ],
                'Dr Adam Vinci',
                false,
                false,
                false,
            ],
            'hyphenate_double_surname_fun' => [
                [
                    new WordDto('title', 'Dr', 'ok'),
                    new WordDto('forename', 'dan', 'ok'),
                    new WordDto('surname', 'dim', 'fun'),
                    new WordDto('surname', 'vinci', 'ok'),
                ],
                'Dr Dan Dim-Vinci',
                true,
                false,
                false,
            ],
            'hyphenate_double_forename' => [
                [
                    new WordDto('forename', 'hughie', 'fun'),
                    new WordDto('forename', 'louis', 'ok'),
                    new WordDto('surname', 'boot', 'ok'),
                ],
                'Hughie-Louis Boot',
                true,
                false,
                false,
            ],
            'prefix_glued_to_surname' => [
                [
                    new WordDto('forename', 'dave', 'ok'),
                    new WordDto('prefix', 'Mc', 'ok'),
                    new WordDto('surname', 'kinky', 'fun'),
                ],
                'Dave McKinky',
                true,
                false,
                false,
            ],
            'prefix_glued_to_surname_run' => [
                [
                    new WordDto('prefix', 'Mc', 'ok'),
                    new WordDto('surname', 'dim', 'ok'),
                    new WordDto('surname', 'vinci', 'ok'),
                ],
                'McDim-Vinci',
                false,
                false,
                false,
            ],
            'apostrophe_surname' => [
                [
                    new WordDto('title', 'Mr', 'ok'),
                    new WordDto('forename', 'uriah', 'ok'),
                    new WordDto('surname', "o'yak", 'ok'),
                ],
                "Mr Uriah O'Yak",
                false,
                false,
                false,
            ],
            'honorific_and_suffix_as_stored' => [
                [
                    new WordDto('surname', 'boot', 'ok'),
                    new WordDto('suffix', '-tastic', 'ok'),
                    new WordDto('honorific', 'OBE', 'ok'),
                ],
                'Boot -tastic OBE',
                false,
                false,
                false,
            ],
            'boring_and_deferred_flags' => [
                [
                    new WordDto('forename', 'jane', 'ok', deferred: true),
                    new WordDto('surname', 'ray', 'boring'),
                ],
                'Jane Ray',
                false,
                true,
                true,
            ],
        ];
    }

    #[Test]
    #[DataProvider('providePhrases')]
    public function from_words_formats_phrase_and_flags(
        array $words,
        string $phrase,
        bool $isFun,
        bool $hasBoring,
        bool $hasDeferred
    ): void {
        $actual = PhraseDto::fromWords($words);
        $this->assertSame($phrase, $actual->phrase);
        $this->assertSame($isFun, $actual->isFun);
        $this->assertSame($hasBoring, $actual->hasBoring);
        $this->assertSame($hasDeferred, $actual->hasDeferred);
    }
}
