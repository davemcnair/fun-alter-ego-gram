<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class TokenWordsBuilderService
{
    /**
     * Build token_words directory from storage/app/altego sources to storage/app/token_words.
     * Optionally copy to a destination within the repo.
     */
    public function build(bool $save = false, ?string $dest = null): array
    {
        $baseAltego = storage_path('app/altego');
        $baseToken = storage_path('app/token_words');

        if (!File::exists($baseToken)) {
            File::makeDirectory($baseToken, 0755, true);
        }
        if (!File::isDirectory($baseAltego)) {
            throw new \RuntimeException("Source directory not found: $baseAltego");
        }

        $dirs = array_values(array_filter(File::directories($baseAltego), fn($p) => File::isDirectory($p)));

        $readMerge = function (array $paths) {
            $lines = [];
            foreach ($paths as $p) {
                if ($p && File::exists($p)) {
                    $content = File::get($p);
                    foreach (preg_split('/\R/u', $content) as $line) {
                        $line = trim($line);
                        if ($line !== '') $lines[] = $line;
                    }
                }
            }
            $uniq = [];
            foreach ($lines as $l) { $key = strtolower($l); $uniq[$key] = $l; }
            $result = array_values($uniq);
            usort($result, fn($a,$b) => strcasecmp($a,$b));
            return $result;
        };

        $makeFile = function ($dir, $filename, array $lines) {
            if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);
            $target = $dir . DIRECTORY_SEPARATOR . $filename;
            File::put($target, implode(PHP_EOL, $lines) . (empty($lines) ? '' : PHP_EOL));
        };

        $built = [];
        foreach ($dirs as $dirPath) {
            $group = basename($dirPath);
            $targetDir = $baseToken . DIRECTORY_SEPARATOR . $group;

            $funnyFiles = [
                $dirPath . DIRECTORY_SEPARATOR . 'funny.txt',
                $dirPath . DIRECTORY_SEPARATOR . 'funny_boys.txt',
                $dirPath . DIRECTORY_SEPARATOR . 'funny_girls.txt',
            ];
            $remainderFiles = [
                $dirPath . DIRECTORY_SEPARATOR . 'remainder.txt',
                $dirPath . DIRECTORY_SEPARATOR . 'remainder_boys.txt',
                $dirPath . DIRECTORY_SEPARATOR . 'remainder_girls.txt',
            ];
            $boringFiles = [
                $dirPath . DIRECTORY_SEPARATOR . 'boring.txt',
                $dirPath . DIRECTORY_SEPARATOR . 'boring_boys.txt',
                $dirPath . DIRECTORY_SEPARATOR . 'boring_girls.txt',
            ];

            if ($group === 'forename') {
                $fun = $readMerge($funnyFiles);
                $ok = $readMerge($remainderFiles);
                $makeFile($targetDir, 'fun.txt', $fun);
                $makeFile($targetDir, 'ok.txt', $ok);
                $built[] = ["group"=>$group, "fun"=>count($fun), "ok"=>count($ok)];
            } elseif ($group === 'surname') {
                $fun = $readMerge($funnyFiles);
                $ok = $readMerge($remainderFiles);
                $boring = $readMerge($boringFiles);
                $makeFile($targetDir, 'fun.txt', $fun);
                $makeFile($targetDir, 'ok.txt', $ok);
                $makeFile($targetDir, 'boring.txt', $boring);
                $built[] = ["group"=>$group, "fun"=>count($fun), "ok"=>count($ok), "boring"=>count($boring)];
            } else {
                $ok = $readMerge($remainderFiles);
                $makeFile($targetDir, 'ok.txt', $ok);
                $built[] = ["group"=>$group, "ok"=>count($ok)];
            }
        }

        if ($save || $dest !== null) {
            $destRel = ($dest !== null && $dest !== '') ? $dest : 'resources/token_words';
            $destPath = base_path($destRel);
            if (File::exists($destPath)) {
                File::deleteDirectory($destPath);
            }
            File::makeDirectory($destPath, 0755, true);
            File::copyDirectory($baseToken, $destPath);
        }

        return $built;
    }
}
