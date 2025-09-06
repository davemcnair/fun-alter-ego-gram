<?php

namespace App\Models;

use App\Services\PhraseBuilderService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pattern extends Model
{
    use HasFactory;

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
     * Convert a template like "{title}{forename}{surname:2}" into token slots array.
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
                    $patternTokenPositions[$pos++] = $name;
                }
            }
        }
        return $patternTokenPositions;
    }

    // Returns a human-friendly example string for this pattern's template using PhraseBuilderService
    public function getExampleAttribute(): string
    {
        $tpl = (string)($this->template ?? '');
        if ($tpl === '') return '';

        // Build slot order from template tokens
        $slotOrder = [];
        if (preg_match_all('/\{([a-z]+)(?::(\d+))?\}/i', $tpl, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = strtolower($m[1]);
                $count = isset($m[2]) && (int)$m[2] > 0 ? (int)$m[2] : 1;
                for ($i = 0; $i < $count; $i++) {
                    $slotOrder[] = ['name' => $name, 'pos' => count($slotOrder)];
                }
            }
        }
        if (empty($slotOrder)) return '';

        // Prepare sample words by token according to requirement
        $forenameSamples = ['Hughie', 'Louis'];
        // Surname should be five distinct tokens, used in order for surname:n (up to 5)
        $surnamePieces = ['moist', 'wipe', 'with', 'no', 'additives'];
        $words = [];
        $fnIdx = 0;
        $prevName = '';
        $surnameRunIdx = 0; // index within the current consecutive surname run
        foreach ($slotOrder as $slot) {
            $name = $slot['name'];
            switch ($name) {
                case 'title':
                    $words[] = 'Dr';
                    $surnameRunIdx = 0; // reset when leaving surname run
                    break;
                case 'forename':
                    $words[] = $forenameSamples[$fnIdx % count($forenameSamples)];
                    $fnIdx++;
                    $surnameRunIdx = 0;
                    break;
                case 'initials':
                    $words[] = 'R.';
                    $surnameRunIdx = 0;
                    break;
                case 'prefix':
                    $words[] = 'Mc';
                    $surnameRunIdx = 0;
                    break;
                case 'surname':
                    // If starting a new consecutive surname block, ensure index is at 0
                    if ($prevName !== 'surname') {
                        $surnameRunIdx = 0;
                    }
                    $words[] = $surnamePieces[$surnameRunIdx % count($surnamePieces)];
                    $surnameRunIdx++;
                    break;
                case 'suffix':
                    $words[] = '-tastic';
                    $surnameRunIdx = 0;
                    break;
                case 'honorific':
                    $words[] = 'OBE';
                    $surnameRunIdx = 0;
                    break;
                default:
                    $words[] = '';
                    $surnameRunIdx = 0;
                    break;
            }
            $prevName = $name;
        }

        try {
            $builder = app(PhraseBuilderService::class);
            return $builder->formatPhraseBySlots($words, $slotOrder, false);
        } catch (\Throwable $e) {
            // Fallback: simple join
            return trim(implode(' ', array_filter($words, fn($w) => $w !== '')));
        }
    }
}
