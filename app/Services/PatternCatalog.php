<?php

namespace App\Services;

use App\Dtos\PatternCatalogList;
use App\Dtos\PatternCatalogQuery;
use App\Dtos\PatternCatalogRow;
use App\Models\Pattern;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PatternCatalog
{
    private const TOKEN_OPTIONS = [
        '' => 'All tokens',
        'title' => 'Title',
        'forename' => 'Forename',
        'initials' => 'Initials',
        'prefix' => 'Prefix',
        'surname' => 'Surname',
        'suffix' => 'Suffix',
        'honorific' => 'Honorific',
    ];

    private const PATTERN_TYPES = ['standard', 'longer', 'exotic'];

    public function list(PatternCatalogQuery $query): PatternCatalogList
    {
        $builder = Pattern::query()->orderBy('popularity_rank');
        $token = strtolower(trim($query->token));
        if ($token !== '') {
            match ($token) {
                'title' => $builder->where('has_title', true),
                'forename' => $builder->where('forename_count', '>', 0),
                'initials' => $builder->where('has_initials', true),
                'prefix' => $builder->where('has_prefix', true),
                'surname' => $builder->where('surname_count', '>', 0),
                'suffix' => $builder->where('has_suffix', true),
                'honorific' => $builder->where('has_honorific', true),
                default => null,
            };
        }

        $items = $builder->get()->map(function (Pattern $pattern) {
            return new PatternCatalogRow(
                id: (int) $pattern->id,
                rank: (int) $pattern->popularity_rank,
                type: (string) $pattern->pattern_type,
                template: (string) $pattern->template,
                example: (string) $pattern->example->phrase,
                minLength: (int) $pattern->min_total_length,
            );
        });

        return new PatternCatalogList(
            items: $items,
            tokenOptions: self::TOKEN_OPTIONS,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Pattern
    {
        $payload = $this->normalizeWriteData($data);
        $this->assertUniqueTemplate($payload['template']);

        return Pattern::create($payload);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Pattern $pattern, array $data): Pattern
    {
        $payload = $this->normalizeWriteData($data);
        $this->assertUniqueTemplate($payload['template'], (int) $pattern->id);
        $pattern->update($payload);

        return $pattern->fresh();
    }

    public function delete(Pattern $pattern): void
    {
        $pattern->delete();
    }

    public function setType(Pattern $pattern, string $type): Pattern
    {
        $normalized = strtolower($type);
        if (! in_array($normalized, self::PATTERN_TYPES, true)) {
            throw new InvalidArgumentException('Invalid pattern type.');
        }
        $pattern->pattern_type = $normalized;
        $pattern->save();

        return $pattern;
    }

    /**
     * @return array{list: array<int, array{template:string, score:int}>, stored?:int}
     */
    public function generate(bool $dryRun = false): array
    {
        $tokens = DB::table('tokens')->get()->keyBy('name');

        $getMax = function (string $name, int $default) use ($tokens) {
            $t = $tokens->get($name);
            return $t ? (int) $t->max_multiples : $default;
        };
        $getMinLen = function (string $name, int $default = 0) use ($tokens) {
            $t = $tokens->get($name);
            return $t ? (int) $t->min_length : $default;
        };
        $exists = function (string $name) use ($tokens) {
            return (bool) $tokens->get($name);
        };

        if (! $exists('surname')) {
            throw new RuntimeException('Missing required token: surname');
        }

        $maxForename = $getMax('forename', 2);
        $maxSurname = $getMax('surname', 5);
        $hasTitle = $exists('title');
        $hasInitials = $exists('initials');
        $hasPrefix = $exists('prefix');
        $hasSuffix = $exists('suffix');
        $hasHonorific = $exists('honorific');

        $patterns = [];
        $buildTemplate = function (int $title, int $fn, int $ini, int $pre, int $sn, int $suf, int $hon) {
            $parts = [];
            if ($title > 0) {
                $parts[] = '{title}';
            }
            if ($fn > 0) {
                $parts[] = $fn === 1 ? '{forename}' : '{forename:'.$fn.'}';
            }
            if ($ini > 0) {
                $parts[] = '{initials}';
            }
            if ($pre > 0) {
                $parts[] = '{prefix}';
            }
            if ($sn > 0) {
                $parts[] = $sn === 1 ? '{surname}' : '{surname:'.$sn.'}';
            }
            if ($suf > 0) {
                $parts[] = '{suffix}';
            }
            if ($hon > 0) {
                $parts[] = '{honorific}';
            }
            return implode('', $parts);
        };

        for ($sn = 1; $sn <= $maxSurname; $sn++) {
            for ($fn = 0; $fn <= $maxForename; $fn++) {
                for ($title = 0; $title <= ($hasTitle ? 1 : 0); $title++) {
                    for ($ini = 0; $ini <= ($hasInitials ? 1 : 0); $ini++) {
                        for ($pre = 0; $pre <= ($hasPrefix ? 1 : 0); $pre++) {
                            for ($suf = 0; $suf <= ($hasSuffix ? 1 : 0); $suf++) {
                                for ($hon = 0; $hon <= ($hasHonorific ? 1 : 0); $hon++) {
                                    if ($pre > 0 && $sn === 0) {
                                        continue;
                                    }
                                    if ($suf > 0 && $sn === 0) {
                                        continue;
                                    }
                                    if ($fn >= 2 && $ini > 0) {
                                        continue;
                                    }
                                    if ($sn > 0 && ($title === 0 && $fn === 0 && $ini === 0)) {
                                        continue;
                                    }
                                    if ($fn > 0 && ($title === 0 && $sn === 0 && $hon === 0)) {
                                        continue;
                                    }
                                    $typesCount = 0;
                                    if ($title > 0) {
                                        $typesCount++;
                                    }
                                    if ($fn > 0) {
                                        $typesCount++;
                                    }
                                    if ($ini > 0) {
                                        $typesCount++;
                                    }
                                    if ($pre > 0) {
                                        $typesCount++;
                                    }
                                    if ($sn > 0) {
                                        $typesCount++;
                                    }
                                    if ($suf > 0) {
                                        $typesCount++;
                                    }
                                    if ($hon > 0) {
                                        $typesCount++;
                                    }
                                    if ($typesCount < 2) {
                                        continue;
                                    }
                                    $template = $buildTemplate($title, $fn, $ini, $pre, $sn, $suf, $hon);
                                    $patterns[$template] = [
                                        'title' => $title,
                                        'fn' => $fn,
                                        'ini' => $ini,
                                        'pre' => $pre,
                                        'sn' => $sn,
                                        'suf' => $suf,
                                        'hon' => $hon,
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        $list = [];
        foreach ($patterns as $tpl => $p) {
            $list[] = [
                'template' => $tpl,
                'meta' => $p,
                'score' => $this->score($p, $tpl),
            ];
        }
        usort($list, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                $la = strlen($a['template']);
                $lb = strlen($b['template']);
                if ($la === $lb) {
                    return strcmp($a['template'], $b['template']);
                }
                return $la <=> $lb;
            }
            return $a['score'] <=> $b['score'];
        });

        $result = ['list' => array_map(fn ($r) => ['template' => $r['template'], 'score' => $r['score']], $list)];

        if (! $dryRun) {
            try {
                DB::table('patterns')->delete();
            } catch (Throwable $e) {
            }
            $rank = 1;
            $now = now();
            $batch = [];
            $minLenTitle = $getMinLen('title', 0);
            $minLenForename = $getMinLen('forename', 0);
            $minLenInitials = $getMinLen('initials', 0);
            $minLenPrefix = $getMinLen('prefix', 0);
            $minLenSurname = $getMinLen('surname', 0);
            $minLenSuffix = $getMinLen('suffix', 0);
            $minLenHonorific = $getMinLen('honorific', 0);
            foreach ($list as $row) {
                $p = $row['meta'];
                $minTotal = 0;
                if ($p['title'] > 0) {
                    $minTotal += $minLenTitle;
                }
                if ($p['fn'] > 0) {
                    $minTotal += $minLenForename * $p['fn'];
                }
                if ($p['ini'] > 0) {
                    $minTotal += $minLenInitials;
                }
                if ($p['pre'] > 0) {
                    $minTotal += $minLenPrefix;
                }
                if ($p['sn'] > 0) {
                    $minTotal += $minLenSurname * $p['sn'];
                }
                if ($p['suf'] > 0) {
                    $minTotal += $minLenSuffix;
                }
                if ($p['hon'] > 0) {
                    $minTotal += $minLenHonorific;
                }
                $batch[] = [
                    'template' => $row['template'],
                    'popularity_rank' => $rank++,
                    'min_total_length' => $minTotal,
                    'forename_count' => $p['fn'],
                    'surname_count' => $p['sn'],
                    'has_title' => $p['title'] > 0,
                    'has_initials' => $p['ini'] > 0,
                    'has_prefix' => $p['pre'] > 0,
                    'has_suffix' => $p['suf'] > 0,
                    'has_honorific' => $p['hon'] > 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (count($batch) >= 500) {
                    DB::table('patterns')->insert($batch);
                    $batch = [];
                }
            }
            if (! empty($batch)) {
                DB::table('patterns')->insert($batch);
            }
            $result['stored'] = $rank - 1;
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     */
    public function reorder(array $ids): int
    {
        DB::transaction(function () use ($ids) {
            $rank = 1;
            foreach ($ids as $id) {
                DB::table('patterns')->where('id', $id)->update(['popularity_rank' => $rank++]);
            }
        });

        return count($ids);
    }

    /**
     * @return array{ok: bool, file?: string, count?: int, error?: string}
     */
    public function export(): array
    {
        $items = Pattern::query()->orderBy('popularity_rank')->get();
        $out = [];
        $includeType = Schema::hasColumn('patterns', 'pattern_type');
        foreach ($items as $p) {
            $row = [
                'template' => $p->template,
                'popularity_rank' => (int) $p->popularity_rank,
                'min_total_length' => (int) $p->min_total_length,
                'forename_count' => (int) $p->forename_count,
                'surname_count' => (int) $p->surname_count,
                'has_title' => (bool) $p->has_title,
                'has_initials' => (bool) $p->has_initials,
                'has_prefix' => (bool) $p->has_prefix,
                'has_suffix' => (bool) $p->has_suffix,
                'has_honorific' => (bool) $p->has_honorific,
            ];
            if ($includeType) {
                $row['pattern_type'] = (string) $p->pattern_type;
            }
            $out[] = $row;
        }
        $dir = resource_path('patterns');
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $file = $dir.DIRECTORY_SEPARATOR.'patterns.json';
        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'Failed to encode JSON'];
        }
        file_put_contents($file, $json);

        return ['ok' => true, 'file' => $file, 'count' => count($out)];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   template: string,
     *   popularity_rank: int,
     *   min_total_length: int,
     *   forename_count: int,
     *   surname_count: int,
     *   has_title: bool,
     *   has_initials: bool,
     *   has_prefix: bool,
     *   has_suffix: bool,
     *   has_honorific: bool
     * }
     */
    private function normalizeWriteData(array $data): array
    {
        return [
            'template' => (string) ($data['template'] ?? ''),
            'popularity_rank' => (int) ($data['popularity_rank'] ?? 1),
            'min_total_length' => (int) ($data['min_total_length'] ?? 0),
            'forename_count' => (int) ($data['forename_count'] ?? 0),
            'surname_count' => (int) ($data['surname_count'] ?? 0),
            'has_title' => (bool) ($data['has_title'] ?? false),
            'has_initials' => (bool) ($data['has_initials'] ?? false),
            'has_prefix' => (bool) ($data['has_prefix'] ?? false),
            'has_suffix' => (bool) ($data['has_suffix'] ?? false),
            'has_honorific' => (bool) ($data['has_honorific'] ?? false),
        ];
    }

    private function assertUniqueTemplate(string $template, ?int $ignoreId = null): void
    {
        $query = Pattern::query()->where('template', $template);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw new InvalidArgumentException('Pattern template must be unique.');
        }
    }

    /**
     * @param array{title:int, fn:int, ini:int, pre:int, sn:int, suf:int, hon:int} $p
     */
    private function score(array $p, string $tpl): int
    {
        $segments = ($p['title'] > 0) + ($p['fn'] > 0 ? $p['fn'] : 0) + ($p['ini'] > 0) + ($p['pre'] > 0) + ($p['sn'] > 0 ? $p['sn'] : 0) + ($p['suf'] > 0) + ($p['hon'] > 0);
        $score = 0;
        $score += $segments * 10;
        if ($p['title'] > 0) {
            $score += 3;
        }
        if ($p['ini'] > 0) {
            $score += 20;
        }
        if ($p['pre'] > 0) {
            $score += 8;
        }
        if ($p['suf'] > 0) {
            $score += 5;
        }
        if ($p['hon'] > 0) {
            $score += 9;
        }
        if ($p['fn'] === 2) {
            $score += 40;
        } elseif ($p['fn'] > 2) {
            $score += 40 + ($p['fn'] - 2) * 8;
        }
        if ($p['fn'] > 1) {
            $score += ($p['fn'] - 1) * 2;
        }
        if ($p['sn'] > 1) {
            $score += ($p['sn'] - 1) * 2;
        }
        if ($p['sn'] >= 3) {
            $score += match ($p['sn']) {
                3 => 35,
                4 => 55,
                default => 75,
            };
        }
        if ($p['fn'] === 0) {
            $score += 30;
        }
        if ($tpl === '{forename}{surname}') {
            return -1000;
        }
        if ($tpl === '{forename}{surname:2}') {
            return -999;
        }
        if ($tpl === '{title}{forename}{surname}') {
            return -998;
        }
        return $score;
    }
}
