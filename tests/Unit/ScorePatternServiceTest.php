<?php

namespace Tests\Unit;

use App\Services\ScorePatternService;
use PHPUnit\Framework\TestCase;

class ScorePatternServiceTest extends TestCase
{
    private function meta(array $overrides = []): array
    {
        // Defaults: title=0, fn=1, ini=0, pre=0, sn=1, suf=0, hon=0
        return array_merge([
            'title' => 0,
            'fn' => 1,
            'ini' => 0,
            'pre' => 0,
            'sn' => 1,
            'suf' => 0,
            'hon' => 0,
        ], $overrides);
    }

    public function test_baseline_top_three_templates_order(): void
    {
        $svc = new ScorePatternService();
        $s1 = $svc->score($this->meta(), '{forename}{surname}');
        $s2 = $svc->score($this->meta(['fn' => 2]), '{forename}{surname:2}');
        $s3 = $svc->score($this->meta(['title' => 1]), '{title}{forename}{surname}');
        $this->assertTrue($s1 < $s2 && $s2 < $s3, 'Expected {forename}{surname} < {forename}{surname:2} < {title}{forename}{surname}');
    }

    public function test_two_forenames_penalized_vs_one(): void
    {
        $svc = new ScorePatternService();
        $one = $svc->score($this->meta(['fn' => 1]), '{forename}{surname}');
        $two = $svc->score($this->meta(['fn' => 2]), '{forename}{surname:2}');
        $this->assertTrue($one < $two, 'Two forenames should be penalized relative to one.');
    }

    public function test_three_surnames_penalized_vs_two(): void
    {
        $svc = new ScorePatternService();
        $two = $svc->score($this->meta(['sn' => 2]), '{forename}{surname:2}');
        $three = $svc->score($this->meta(['sn' => 3]), '{forename}{surname:3}');
        $this->assertTrue($two < $three, 'Three surnames should be penalized relative to two.');
    }

    public function test_forename2_patterns_lower_than_single_surname_patterns(): void
    {
        $svc = new ScorePatternService();
        // Two forenames + one surname should be worse (higher score)
        $fn2_sn1 = $svc->score($this->meta(['fn' => 2, 'sn' => 1]), '{forename:2}{surname}');
        // Representative single-surname patterns
        $fn1_sn1 = $svc->score($this->meta(['fn' => 1, 'sn' => 1]), '{forename}{surname}');
        $title_sn1 = $svc->score($this->meta(['title' => 1, 'fn' => 0, 'sn' => 1]), '{title}{surname}');

        $this->assertTrue($fn1_sn1 < $fn2_sn1, 'Two-forename patterns should rank below the basic single-surname pattern.');
        $this->assertTrue($title_sn1 < $fn2_sn1, 'Two-forename patterns should also rank below {title}{surname}.');
    }

    public function test_forename2_patterns_lower_than_double_surname_patterns(): void
    {
        $svc = new ScorePatternService();
        // Two forenames + two surnames should be worse (higher score)
        $fn2_sn1 = $svc->score($this->meta(['fn' => 2, 'sn' => 1]), '{forename:2}{surname:2}');
        // Representative double-surname patterns
        $fn1_sn1 = $svc->score($this->meta(['fn' => 1, 'sn' => 1]), '{forename}{surname:2}');
        $title_sn1 = $svc->score($this->meta(['title' => 1, 'fn' => 0, 'sn' => 1]), '{title}{surname:2}');

        $this->assertTrue($fn1_sn1 < $fn2_sn1, 'Two-forename patterns should rank below the basic double-surname pattern.');
        $this->assertTrue($title_sn1 < $fn2_sn1, 'Two-forename patterns should also rank below {title}{surname:2}.');
    }

    public function test_surname3plus_always_lower_than_surname2(): void
    {
        $svc = new ScorePatternService();
        // Vary some representative factors (title present/absent, forename counts 0..2)
        foreach ([0, 1] as $title) {
            foreach ([0, 1, 2] as $fn) {
                // Keep other flags off to compare fairly
                $baseMeta = $this->meta(['title' => $title, 'fn' => $fn]);
                $sn2 = $svc->score($this->meta(array_merge($baseMeta, ['sn' => 2])), '{forename' . ($fn>1?':'.$fn:'').'}{surname:2}');
                foreach ([3, 4, 5] as $sn) {
                    $snK = $svc->score($this->meta(array_merge($baseMeta, ['sn' => $sn])), '{forename' . ($fn>1?':'.$fn:'').'}{surname:'.$sn.'}');
                    $this->assertTrue($sn2 < $snK, sprintf('Expected sn=2 to outrank sn=%d for title=%d, fn=%d', $sn, $title, $fn));
                }
            }
        }
    }
}
