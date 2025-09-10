<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Models\Word;
use App\Models\AnagramGroup;
use Illuminate\Support\Facades\DB;

class ImportWordLists extends Command
{
    protected $signature = 'words:import {base=storage/app/wordlists} {--reconcile-all}';
    protected $description = 'Import token-type word lists (plain text) into the database and reconcile anagram groups. Example bases: resources/wordlists or storage/app/wordlists';

    public function handle()
    {
        $basePath = $this->argument('base');
        $reconcileAll = (bool)$this->option('reconcile-all');

        if (!File::exists($basePath)) {
            $this->warn("Base path not found: {$basePath}");
            return self::FAILURE;
        }

        $affected = [];

        DB::transaction(function () use ($basePath, &$affected) {
            foreach (File::directories($basePath) as $tokenTypePath) {
                $tokenType = basename($tokenTypePath);

                foreach (File::files($tokenTypePath) as $file) {
                    // $file is SplFileInfo
                    $listType = pathinfo($file->getFilename(), PATHINFO_FILENAME); // ok, fun, boring
                    $lines = @file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

                    foreach ($lines as $word) {
                        // Normalize: lowercase, remove punctuation (ASCII-only)
                        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $word));

                        // Signature: sorted letters (ASCII-only)
                        $letters = str_split($normalized);
                        sort($letters);
                        $signature = implode('', $letters);

                        // Skip storing words that normalize to an empty signature
                        if ($signature === '') {
                            continue;
                        }

                        // Track affected pair for later reconciliation
                        $affected[$tokenType . '|' . $signature] = [
                            'token_type' => $tokenType,
                            'signature' => $signature,
                        ];

                        // Ensure anagram group exists
                        $group = AnagramGroup::firstOrCreate(
                            ['token_type' => $tokenType, 'signature' => $signature],
                            ['words_count' => 0]
                        );

                        $w = Word::updateOrCreate(
                            [
                                'word' => $word,
                                'token_type' => $tokenType,
                                'list_type' => $listType,
                            ],
                            [
                                'signature' => $signature,
                                'anagram_group_id' => $group->id,
                            ]
                        );

                        // Increment words_count when newly attached/created
                        if ($w->wasRecentlyCreated || (int)$w->anagram_group_id !== (int)$group->id) {
                            $group->increment('words_count');
                        }
                    }
                }
            }
        });

        // Reconciliation pass: incorporate BackfillAnagramGroups logic
        if ($reconcileAll) {
            $this->info('Reconciling ALL anagram groups (global backfill)...');
            DB::transaction(function () {
                $rows = DB::table('words')
                    ->select('token_type', 'signature', DB::raw('COUNT(*) as c'))
                    ->groupBy('token_type', 'signature')
                    ->get();

                foreach ($rows as $row) {
                    if (!$row->signature) continue;
                    $group = AnagramGroup::firstOrCreate(
                        ['token_type' => (string)$row->token_type, 'signature' => (string)$row->signature],
                        ['words_count' => 0]
                    );
                    // Assign words missing group id
                    Word::where('token_type', $row->token_type)
                        ->where('signature', $row->signature)
                        ->where(function($q){ $q->whereNull('anagram_group_id')->orWhere('anagram_group_id', 0); })
                        ->update(['anagram_group_id' => $group->id]);
                    // Update count to actual number of words attached for this group
                    $count = Word::where('anagram_group_id', $group->id)->count();
                    $group->words_count = $count;
                    $group->save();
                }
            });
        } else if (!empty($affected)) {
            $this->info('Reconciling anagram groups for imported signatures...');
            DB::transaction(function () use ($affected) {
                foreach ($affected as $pair) {
                    $token = (string)$pair['token_type'];
                    $sig = (string)$pair['signature'];
                    if ($sig === '') continue;
                    $group = AnagramGroup::firstOrCreate(
                        ['token_type' => $token, 'signature' => $sig],
                        ['words_count' => 0]
                    );
                    Word::where('token_type', $token)
                        ->where('signature', $sig)
                        ->where(function($q){ $q->whereNull('anagram_group_id')->orWhere('anagram_group_id', 0); })
                        ->update(['anagram_group_id' => $group->id]);
                    $count = Word::where('anagram_group_id', $group->id)->count();
                    $group->words_count = $count;
                    $group->save();
                }
            });
        }

        $this->info('Imported all word lists with normalization and anagram grouping (reconciled).');
        return self::SUCCESS;
    }
}
