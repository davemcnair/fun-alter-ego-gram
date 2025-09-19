<?php

namespace App\Services;

use App\Models\TokenSignatureWord;
use App\Traits\HelpsMatchWords;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class WordCommitService
{
    use HelpsMatchWords;

    public function commit(): array
    {
        // Detect uncommitted
        $pending = TokenSignatureWord::query()
            ->with(['tokenSignature.token'])
            ->whereNull('committed_at')
            ->get();
        $count = $pending->count();
        if ($count === 0) {
            return ['ok' => true, 'committed_count' => 0, 'backup' => null, 'changes' => []];
        }

        $resourcesBase = base_path('resources/token_words');
        $backupBase = base_path('resources/token_words_backup');
        File::ensureDirectoryExists($backupBase);

        $timestamp = now()->format('Ymd_His');
        $backupName = 'token_words_' . $timestamp . '.zip';
        $backupPath = $backupBase . DIRECTORY_SEPARATOR . $backupName;

        // Create zip backup of the entire token_words directory
        $zip = new ZipArchive();
        if ($zip->open($backupPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Unable to create backup zip: ' . $backupPath);
        }
        $this->zipDir($zip, $resourcesBase, 'token_words');
        $zip->close();

        // Prepare changelog lines
        $changelog = [];
        $changelog[] = '[' . now()->toDateTimeString() . '] backup=' . $backupName . ' changes=' . $count;
        foreach ($pending as $row) {
            $token = (string)($row->tokenSignature?->token?->name ?? '');
            $sig = (string)($row->tokenSignature?->signature ?? '');
            $changelog[] = 'add: token=' . $token . ' list=' . $row->list_type . ' word=' . $row->word . ' signature=' . $sig;
        }
        $changelogPath = $backupBase . DIRECTORY_SEPARATOR . 'changelog.txt';
        File::append($changelogPath, implode(PHP_EOL, $changelog) . PHP_EOL);

        // Merge into resources/token_words
        $grouped = [];
        foreach ($pending as $row) {
            $token = (string)($row->tokenSignature?->token?->name ?? '');
            if ($token === '') continue; // skip if not resolvable
            $list = strtolower((string)$row->list_type);
            $grouped[$token][$list][] = $this->normalize($row->word);
        }

        // Write updates per token/list, using temp files and rename
        foreach ($grouped as $token => $lists) {
            $tokenDir = $resourcesBase . DIRECTORY_SEPARATOR . $token;
            File::ensureDirectoryExists($tokenDir);
            foreach ($lists as $list => $words) {
                $filename = $tokenDir . DIRECTORY_SEPARATOR . $list . '.txt';
                $existing = [];
                if (File::exists($filename)) {
                    $existing = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                }
                // Normalize existing lines
                $existing = array_map(fn($w) => trim((string)$w), $existing);
                $all = array_merge($existing, array_map(fn($w) => trim((string)$w), $words));
                // Deduplicate case-insensitive
                $uniqMap = [];
                foreach ($all as $w) {
                    if ($w === '') continue;
                    $key = strtolower($w);
                    $uniqMap[$key] = $w; // keep last occurrence formatting
                }
                $final = array_values($uniqMap);
                // Sort case-insensitive ascending
                usort($final, function($a, $b) { return strcasecmp($a, $b); });

                // Write to temp file then rename
                $tmp = $filename . '.tmp';
                $content = implode(PHP_EOL, $final) . PHP_EOL;
                File::put($tmp, $content);
                // atomic-ish replace
                if (File::exists($filename)) {
                    File::delete($filename);
                }
                File::move($tmp, $filename);
            }
        }

        // Mark committed in a transaction
        DB::transaction(function () use ($pending) {
            $ids = $pending->pluck('id')->all();
            TokenSignatureWord::query()->whereIn('id', $ids)->update(['committed_at' => now()]);
        });

        return ['ok' => true, 'committed_count' => $count, 'backup' => $backupName, 'changes' => $changelog];
    }

    private function zipDir(ZipArchive $zip, string $path, string $rootName): void
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if (!is_dir($path)) {
            // still create empty dir entry
            $zip->addEmptyDir($rootName);
            return;
        }
        $zip->addEmptyDir($rootName);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            $localPath = $rootName . DIRECTORY_SEPARATOR . ltrim(str_replace($path, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            if ($file->isDir()) {
                $zip->addEmptyDir($localPath);
            } else {
                $zip->addFile($file->getPathname(), $localPath);
            }
        }
    }
}
