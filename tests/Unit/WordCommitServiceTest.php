<?php

namespace Tests\Unit;

use App\Models\Token;
use App\Models\TokenSignatureWord;
use App\Services\WordCommitService;
use App\Services\WordMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Carbon\Carbon;

class WordCommitServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $resourcesBase;
    private string $backupBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resourcesBase = storage_path('framework/testing/token_words_resources');
        $this->backupBase = storage_path('framework/testing/token_words_backup');
        // Ensure clean directories for deterministic tests (only within testing sandbox)
        @File::deleteDirectory($this->resourcesBase);
        @File::deleteDirectory($this->backupBase);
        File::ensureDirectoryExists($this->resourcesBase);
        File::ensureDirectoryExists($this->backupBase);
        // Point WordCommitService to sandboxed paths so tests never touch real resources/
        \Config::set('paths.token_words_resources', $this->resourcesBase);
        \Config::set('paths.token_words_backup', $this->backupBase);
        // Freeze time
        Carbon::setTestNow(Carbon::parse('2025-09-15 12:34:56'));
    }

    protected function tearDown(): void
    {
        // Clean up files created by tests
        @File::deleteDirectory($this->resourcesBase);
        @File::deleteDirectory($this->backupBase);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_commit_noop_when_no_uncommitted_words(): void
    {
        $svc = app(WordCommitService::class);
        $result = $svc->commit();
        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame(0, $result['committed_count']);
        $this->assertNull($result['backup']);
        // Ensure no backup/changelog created
        $files = File::files($this->backupBase);
        $this->assertCount(0, $files);
    }

    public function test_commit_merges_writes_backup_changelog_and_marks_committed(): void
    {
        // Arrange: create some uncommitted words under tokens and lists
        /** @var WordMatchService $match */
        $match = app(WordMatchService::class);
        $w1 = $match->addTokenWord('forename', 'Alice', 'ok');
        $w2 = $match->addTokenWord('forename', 'Lacie', 'fun'); // anagram, should both normalize and dedupe handled by file merge
        $w3 = $match->addTokenWord('surname', 'Brown', 'boring');
        $this->assertNull($w1->committed_at);
        $this->assertNull($w2->committed_at);
        $this->assertNull($w3->committed_at);

        // Pre-seed existing files with content to verify merge & sorting
        $forenameDir = $this->resourcesBase . '/forename';
        $surnameDir = $this->resourcesBase . '/surname';
        File::ensureDirectoryExists($forenameDir);
        File::ensureDirectoryExists($surnameDir);
        File::put($forenameDir . '/ok.txt', "bob\nzoe\n");
        File::put($forenameDir . '/fun.txt', "amy\n");
        File::put($surnameDir . '/boring.txt', "adamson\n");

        // Act
        /** @var WordCommitService $svc */
        $svc = app(WordCommitService::class);
        $result = $svc->commit();

        // Assert: response shape
        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame(3, $result['committed_count']);
        $this->assertIsString($result['backup']);
        $this->assertStringStartsWith('token_words_', $result['backup']);
        $this->assertStringEndsWith('.zip', $result['backup']);
        $this->assertNotEmpty($result['changes']);

        // Assert: words marked committed
        $this->assertSame(0, TokenSignatureWord::whereNull('committed_at')->count());
        $this->assertSame(3, TokenSignatureWord::whereNotNull('committed_at')->count());

        // Assert: backup zip exists and changelog has entries
        $backupZip = $this->backupBase . '/' . $result['backup'];
        $this->assertFileExists($backupZip);
        $this->assertFileIsReadable($backupZip);
        $changelogPath = $this->backupBase . '/changelog.txt';
        $this->assertFileExists($changelogPath);
        $changelog = file_get_contents($changelogPath);
        $this->assertStringContainsString('backup=' . $result['backup'], $changelog);
        $this->assertStringContainsString('changes=3', $changelog);
        $this->assertStringContainsString('add: token=forename', $changelog);
        $this->assertStringContainsString('add: token=surname', $changelog);

        // Assert: files merged, normalized, unique and sorted (case-insensitive)
        $okFile = $forenameDir . '/ok.txt';
        $funFile = $forenameDir . '/fun.txt';
        $boringFile = $surnameDir . '/boring.txt';
        $this->assertFileExists($okFile);
        $this->assertFileExists($funFile);
        $this->assertFileExists($boringFile);

        $okLines = array_filter(array_map('trim', file($okFile))); // bob, zoe, alice
        $funLines = array_filter(array_map('trim', file($funFile)));
        $boringLines = array_filter(array_map('trim', file($boringFile)));

        // Normalization in HelpsMatchWords lowercases and strips non-letters
        $this->assertEquals(['alice','bob','zoe'], array_values($okLines));
        $this->assertEquals(['amy','lacie'], array_values($funLines));
        $this->assertEquals(['adamson','brown'], array_values($boringLines));
    }
}
