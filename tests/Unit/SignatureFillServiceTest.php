<?php

namespace Tests\Unit;

use App\Models\Pattern;
use App\Services\SignatureFillService;
use App\Traits\HelpsMatchWords;
use Tests\TestCase;

class SignatureFillServiceTest extends TestCase
{
    use HelpsMatchWords;

    /**
     * Simple happy-path: two slots {forename}{surname} with an exact cover available.
     * Expect a single signaturedPattern in slot order.
     */
    public function test_generates_signature_patterns_exact_cover_in_slot_order(): void
    {
        $svc = new SignatureFillService();
        $patternTokenPositions = Pattern::parsePatternTokenSlotPositions("{forename}{surname}");
        $candidates = [
            'forename' => [
                'Adam' => 'aadm',
                'Dan'  => 'adn',
            ],
            'surname' => [
                'Vinci' => 'ciinv',
                'Ray'   => 'ary',
            ],
        ];
        $sourceSig = 'aadmciinv'; // exact cover: Adam (aadm) + Vinci (ciinv)

        $out = iterator_to_array($svc->generateSignaturePatterns($sourceSig, $patternTokenPositions, $candidates), false);

        $this->assertSame(['{forename:aadm}{surname:ciinv}'], $out);
    }

    /**
     * Duplicate token runs: {surname:2} with only one candidate available can be reused for both slots.
     * Expect a single pattern with the same signature in both positions.
     */
    public function test_duplicate_token_run_allows_reuse_of_same_candidate(): void
    {
        $svc = new SignatureFillService();
        // Two surname slots at positions 0 and 1
        $patternTokenPositions = Pattern::parsePatternTokenSlotPositions("{surname:2}");
        $candidates = [
            'surname' => [ 'Vinci' => 'ciinv' ],
        ];
        $sourceSig = 'ciinvciinv'; // needs two Vinci signatures

        $out = iterator_to_array($svc->generateSignaturePatterns($sourceSig, $patternTokenPositions, $candidates), false);

        $this->assertSame(['{surname:ciinv}{surname:ciinv}'], $out);
    }

    /**
     * Impossible case: unionCanFill / canCover should prune and yield nothing.
     */
    public function test_impossible_case_yields_no_results(): void
    {
        $svc = new SignatureFillService();
        $patternTokenPositions = Pattern::parsePatternTokenSlotPositions("{forename}");
        $candidates = [ 'forename' => [ 'Dan' => 'adn' ] ];
        $sourceSig = 'abc'; // cannot be covered by 'adn'

        $out = iterator_to_array($svc->generateSignaturePatterns($sourceSig, $patternTokenPositions, $candidates), false);

        $this->assertSame([], $out);
    }

    /**
     * Passing a raw name (with spaces and mixed case) should be normalized to a signature internally.
     */
    public function test_accepts_raw_source_and_normalizes_to_signature(): void
    {
        $svc = new SignatureFillService();
        $patternTokenPositions = Pattern::parsePatternTokenSlotPositions("{forename}{surname}");
        $candidates = [
            'forename' => [ 'Adam' => 'aadm' ],
            'surname' => [ 'Vinci' => 'ciinv' ],
        ];
        $rawSource = 'Adam Vinci';
        // makeSignature('Adam Vinci') should be equivalent to 'aadmciinv' ordering-wise for histogram purposes
        $expected = ['{forename:aadm}{surname:ciinv}'];

        $out = iterator_to_array($svc->generateSignaturePatterns($rawSource, $patternTokenPositions, $candidates), false);

        $this->assertSame($expected, $out);
    }
}
