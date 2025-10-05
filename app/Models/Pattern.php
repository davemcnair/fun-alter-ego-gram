<?php

namespace App\Models;

use App\Dtos\PhraseDto;
use App\Dtos\WordDto;
use App\Services\PhraseBuilderService;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class Pattern extends Model
{

    protected $fillable = [
        'template',
        'popularity_rank',
        'pattern_type',
        'min_total_length',
        'forename_count',
        'surname_count',
        'has_title',
        'has_initials',
        'has_prefix',
        'has_suffix',
        'has_honorific',
    ];

    public function has(string $tokenType): bool
    {
        return match($tokenType) {
            Token::TOKEN_NAME_TITLE => $this->has_title,
            Token::TOKEN_NAME_FORENAME => $this->forename_count > 0,
            Token::TOKEN_NAME_INITIALS => $this->has_initials,
            Token::TOKEN_NAME_PREFIX => $this->has_prefix,
            Token::TOKEN_NAME_SURNAME => $this->surname_count > 0,
            Token::TOKEN_NAME_SUFFIX => $this->has_suffix,
            Token::TOKEN_NAME_HONORIFIC => $this->has_honorific,
        };
    }

    /**
     * Convert a pattern template like "{title}{forename}{surname:2}" into an array of slot descriptors.
     * A suffix like :2 means the token may appear twice (e.g., two surnames).
     * Example:
     *   Input:  "{title}{forename}{surname:2}"
     *
     * @return string[]
*/
    public static function parsePatternTokenSlotPositions(string $template): array
    {
        $patternTokenPositions = [];
        $pos = 0;
        if (preg_match_all('/\{([a-z]+)(?::(\d+))?\}/i', $template, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $name = strtolower($match[1]);
                $count = isset($match[2]) && ctype_digit($match[2]) ? max(1, (int)$match[2]) : 1;
                for ($i = 0; $i < $count; $i++) {
                    $patternTokenPositions[$pos++] = Token::lookup($name)->id;
                }
            }
        }
        return $patternTokenPositions;
    }

    // Returns a human-friendly example string for this pattern's template using PhraseBuilderService
    public function getExampleAttribute(): PhraseDto
    {
        // Prepare sample words by token according to requirement
        $samples = [
            'title' => ['ok:Dr'],
            'forename' =>['fun:Hughie', 'ok:Louis'],
            'initials' => ['ok:R.'],
            'prefix' => ['ok:Mc'],
            'surname' => ['fun:moist', 'fun:wipe', 'ok:with', 'ok:no', 'boring:additives'],
            'suffix' =>['ok:-tastic'],
            'honorific' =>['ok:OBE'],
        ];

        // Build slot order from template tokens
        $slots = [];
        $slotIx = 0;
        if (preg_match_all('/\{([a-z]+)(?::(\d+))?\}/i', $this->template, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = strtolower($m[1]);
                $count = isset($m[2]) && (int)$m[2] > 0 ? (int)$m[2] : 1;
                for ($i = 0; $i < $count; $i++) {
                    [$type, $word] = explode(":", $samples[$name][$i]);
                    $slots[$slotIx++] = new WordDto( $name, $word, $type);
                }
            }
        }

        return PhraseDto::fromWords($slots);
    }
}
