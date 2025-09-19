<?php

namespace Tests\Unit;

use App\Models\Target;
use App\Models\Token;
use App\Models\TokenSignatureWord;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordMatchServiceSqlPruningParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed minimal tokens used in tests
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 0],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 0],
        ]);
    }

    private function countsFromSignature(string $sig): array
    {
        $counts = [];
        $len = strlen($sig);
        for ($i=0; $i<$len; $i++) {
            $ch = $sig[$i];
            $counts[$ch] = ($counts[$ch] ?? 0) + 1;
        }
        return $counts;
    }

    private function sqlPrunedQuery(string $targetSignature, array $options = [])
    {
        $srcLen = strlen($targetSignature);
        $counts = $this->countsFromSignature($targetSignature);
        $filterToken = (string)($options['token'] ?? '');
        $filterList = (string)($options['list'] ?? '');
        $includeBoring = (bool)($options['include_boring'] ?? false);

        return TokenSignatureWord::query()
            ->with(['tokenSignature.token'])
            ->where('is_deferred', false)
            ->when($filterList !== '', function($q) use ($filterList) {
                $q->where('list_type', $filterList);
            }, function($q) use ($includeBoring) {
                if (!$includeBoring) {
                    $q->where('list_type', '!=', 'boring');
                }
            })
            ->whereHas('tokenSignature', function($q) use ($srcLen, $counts, $filterToken) {
                $q->where('length', '<=', $srcLen);
                // Enforce exact subset: letters present must be <= target counts; letters absent must be 0
                foreach (range('a','z') as $ch) {
                    $n = (int)($counts[$ch] ?? 0);
                    if ($n > 0) {
                        $q->where($ch . '_count', '<=', $n);
                    } else {
                        $q->where($ch . '_count', '=', 0);
                    }
                }
                if ($filterToken !== '') {
                    $q->whereHas('token', function($t) use ($filterToken) {
                        $t->where('name', $filterToken);
                    });
                }
            });
    }

    public function test_sql_pruning_equals_php_filtering_basic(): void
    {
        $svc = app(WordMatchService::class);
        // Add a mix of words for forename
        $svc->addTokenWord('forename', 'aa', 'ok');     // sig: aa
        $svc->addTokenWord('forename', 'ab', 'ok');     // ab
        $svc->addTokenWord('forename', 'abc', 'ok');    // abc
        $svc->addTokenWord('forename', 'b', 'ok');      // b
        $svc->addTokenWord('forename', 'cc', 'ok');     // cc
        $svc->addTokenWord('forename', 'cab', 'boring'); // abc (boring)
        $svc->addTokenWord('forename', 'dddd', 'ok');   // dddd (too long / letters not in target)

        $targetSig = 'aabcc'; // a:2, b:1, c:2

        // Default: exclude boring
        $phpMatches = $svc->findMatchingTokenSignatureWords($targetSig);
        $sqlMatches = $this->sqlPrunedQuery($targetSig)->get();

        $this->assertEqualsCanonicalizing(
            $phpMatches->pluck('id')->all(),
            $sqlMatches->pluck('id')->all(),
            'SQL-pruned set should equal PHP-filtered set'
        );

        // Include boring
        $phpMatchesInc = $svc->findMatchingTokenSignatureWords($targetSig, ['include_boring' => true]);
        $sqlMatchesInc = $this->sqlPrunedQuery($targetSig, ['include_boring' => true])->get();
        $this->assertEqualsCanonicalizing(
            $phpMatchesInc->pluck('id')->all(),
            $sqlMatchesInc->pluck('id')->all(),
            'Including boring should match in both modes'
        );

        // Filter by list type explicitly
        $phpOk = $svc->findMatchingTokenSignatureWords($targetSig, ['list' => 'ok']);
        $sqlOk = $this->sqlPrunedQuery($targetSig, ['list' => 'ok'])->get();
        $this->assertEqualsCanonicalizing($phpOk->pluck('id')->all(), $sqlOk->pluck('id')->all());
    }
}
