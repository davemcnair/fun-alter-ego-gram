<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TokenType;
use App\Models\Signature;
use App\Models\TokenWord;
use Illuminate\Support\Str;

class ImportTokenWords extends Command
{
    protected $signature = 'tokens:import {file} {tokenType} {--deferred}';
    protected $description = 'Import curated token words into the database';

    public function handle()
    {
        $file = $this->argument('file');
        $tokenTypeName = $this->argument('tokenType');
        $isDeferred = $this->option('deferred');

        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        // Ensure token type exists
        $tokenType = TokenType::firstOrCreate(['name' => $tokenTypeName]);

        // Read file line by line
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $original = trim($line);

            // Normalize and generate signature
            $normalized = Str::of($original)->lower()->replaceMatches('/[^a-z]/', '');
            if ($normalized->isEmpty()) {
                $this->warn("Skipping empty normalized word: {$original}");
                continue;
            }

            $signature = collect(str_split($normalized))
                ->sort()
                ->join('');

            // Ensure signature exists
            $signatureModel = Signature::firstOrCreate([
                'token_type_id' => $tokenType->id,
                'signature'     => $signature,
            ]);

            // Insert word
            TokenWord::firstOrCreate([
                'signature_id' => $signatureModel->id,
                'word_original'=> $original,
                'is_deferred'  => $isDeferred,
            ]);
        }

        $this->info("Import completed for tokenType={$tokenTypeName}");
        return 0;
    }
}

