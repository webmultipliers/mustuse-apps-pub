<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Jobs;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Jobs\ProjectBuildToGithub;
use MustUse\Pub\Support\GitHubProjector;
use PHPUnit\Framework\TestCase;

/**
 * Freezes the projection-job state-machine contract:
 *
 *   success       → meta status='projected', commit/branch/compare_url set,
 *                   previous error cleared, buildPath wiped
 *   projector fail → meta status='failed', error recorded, buildPath wiped
 *   config missing → meta status='failed', never invokes the projector
 *
 * A subclass of GitHubProjector replaces the real HTTP client with a fake
 * that returns canned Git Data API responses so the test never touches the
 * network.
 */
final class ProjectBuildToGithubTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $postMeta = [];
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        Monkey\setUp();

        $this->postMeta = [];

        Functions\when('get_post_meta')->alias(
            function (int $postId, string $key) {
                if (! isset($this->postMeta[$postId][$key])) {
                    return '';
                }
                return $this->postMeta[$postId][$key];
            }
        );

        Functions\when('update_post_meta')->alias(
            function (int $postId, string $key, mixed $value) {
                $this->postMeta[$postId][$key] = $value;
                return true;
            }
        );

        Functions\when('delete_post_meta')->alias(
            function (int $postId, string $key) {
                unset($this->postMeta[$postId][$key]);
                return true;
            }
        );

        Functions\when('current_time')->justReturn('2026-04-15 00:00:00');
        Functions\when('wp_json_encode')->alias(static fn ($v) => \json_encode($v));

        TestableProjector::$nextResponse = null;
        TestableProjector::$shouldThrow = null;
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        foreach ($this->tempDirs as $dir) {
            self::deleteDir($dir);
        }
        $this->tempDirs = [];
    }

    public function test_happy_path_writes_projected_meta_and_clears_error(): void
    {
        $buildPath = $this->makeTempDirWithFile();

        // Per-app build settings via post meta.
        update_post_meta(42, '_mua_build_repo_url', 'https://github.com/owner/repo.git');
        update_post_meta(42, '_mua_github_token', self::validEncryptedToken());

        // Pre-seed an error so the happy path can clear it.
        update_post_meta(42, '_mua_last_build_error', 'previous failure');

        TestableProjector::$nextResponse = [
            'branch'          => 'build/my-app-1234567890',
            'commit_sha'      => 'abc123',
            'compare_url'     => 'https://github.com/owner/repo/compare/main...build/my-app-1234567890',
            'files_committed' => 42,
            'default_branch'  => 'main',
        ];

        TestableProjectBuildToGithub::handle(42, $buildPath, 'build/my-app-1234567890', '[mua] test');

        self::assertSame('projected', $this->postMeta[42]['_mua_last_build_status']);
        self::assertSame('build/my-app-1234567890', $this->postMeta[42]['_mua_last_build_branch']);
        self::assertSame('abc123', $this->postMeta[42]['_mua_last_build_commit']);
        self::assertSame(
            'https://github.com/owner/repo/compare/main...build/my-app-1234567890',
            $this->postMeta[42]['_mua_last_build_compare_url']
        );
        self::assertArrayNotHasKey('_mua_last_build_error', $this->postMeta[42]);

        // buildPath must be wiped — defense in depth against the .env that
        // BuildAssembler no longer writes but may appear if future assembly
        // steps regress.
        self::assertFileDoesNotExist($buildPath);
    }

    public function test_projector_exception_writes_failed_status_and_wipes_buildpath(): void
    {
        $buildPath = $this->makeTempDirWithFile();

        update_post_meta(42, '_mua_build_repo_url', 'https://github.com/owner/repo.git');
        update_post_meta(42, '_mua_github_token', self::validEncryptedToken());

        TestableProjector::$shouldThrow = new \RuntimeException('GitHub API 404: Not Found');

        TestableProjectBuildToGithub::handle(42, $buildPath, 'build/my-app-1', '[mua] test');

        self::assertSame('failed', $this->postMeta[42]['_mua_last_build_status']);
        self::assertStringContainsString('GitHub API 404', $this->postMeta[42]['_mua_last_build_error']);
        self::assertFileDoesNotExist($buildPath);
    }

    public function test_missing_repo_url_fails_without_invoking_projector(): void
    {
        $buildPath = $this->makeTempDirWithFile();

        // Don't seed _mua_build_repo_url — the stub returns '' for missing keys.
        TestableProjectBuildToGithub::handle(42, $buildPath, 'build/my-app-1', '[mua] test');

        self::assertSame('failed', $this->postMeta[42]['_mua_last_build_status']);
        self::assertStringContainsString('repository URL', $this->postMeta[42]['_mua_last_build_error']);
        self::assertNull(TestableProjector::$nextResponse);
    }

    public function test_matching_content_hash_skips_projection_and_records_unchanged(): void
    {
        $buildPath = $this->makeTempDirWithFile();
        update_post_meta(42, '_mua_build_repo_url', 'https://github.com/owner/repo.git');
        update_post_meta(42, '_mua_github_token', self::validEncryptedToken());

        // Pre-compute the hash the projector will produce for this buildPath
        // and seed it as the "last" hash so the change detector fires.
        $expected = (new GitHubProjector('https://github.com/o/r.git', 't'))->computeContentHash($buildPath);
        update_post_meta(42, '_mua_last_projection_hash', $expected);

        // Seed prior branch/commit/compare so the unchanged row can reference them.
        update_post_meta(42, '_mua_last_build_branch', 'build/prior-run');
        update_post_meta(42, '_mua_last_build_commit', 'deadbeef');
        update_post_meta(42, '_mua_last_build_compare_url', 'https://github.com/o/r/compare/main...build/prior-run');

        // Prime the projector response — but it should NEVER be consumed.
        TestableProjector::$nextResponse = [
            'branch'          => 'build/should-not-be-used',
            'commit_sha'      => 'feedbeef',
            'compare_url'     => 'https://example.test/compare',
            'files_committed' => 99,
            'default_branch'  => 'main',
        ];

        TestableProjectBuildToGithub::handle(42, $buildPath, 'build/new-attempt', '[mua] same content');

        self::assertSame('unchanged', $this->postMeta[42]['_mua_last_build_status']);
        self::assertSame('build/prior-run', $this->postMeta[42]['_mua_last_build_branch']);

        $history = $this->postMeta[42]['_mua_ship_history'] ?? [];
        self::assertIsArray($history);
        self::assertCount(1, $history);
        self::assertSame('unchanged', $history[0]['status']);
        self::assertSame('build/prior-run', $history[0]['branch']);
        self::assertSame('deadbeef', $history[0]['commit_sha']);
        self::assertSame(0, $history[0]['files_committed']);
    }

    public function test_happy_path_records_projection_hash_for_next_run(): void
    {
        $buildPath = $this->makeTempDirWithFile();
        update_post_meta(42, '_mua_build_repo_url', 'https://github.com/owner/repo.git');
        update_post_meta(42, '_mua_github_token', self::validEncryptedToken());

        $expectedHash = (new GitHubProjector('https://github.com/o/r.git', 't'))->computeContentHash($buildPath);

        TestableProjector::$nextResponse = [
            'branch'          => 'build/first-run',
            'commit_sha'      => 'abc123',
            'compare_url'     => 'https://github.com/o/r/compare/main...build/first-run',
            'files_committed' => 5,
            'default_branch'  => 'main',
        ];

        TestableProjectBuildToGithub::handle(42, $buildPath, 'build/first-run', '[mua] initial');

        self::assertSame($expectedHash, $this->postMeta[42]['_mua_last_projection_hash']);
        self::assertSame('projected', $this->postMeta[42]['_mua_last_build_status']);

        $history = $this->postMeta[42]['_mua_ship_history'] ?? [];
        self::assertCount(1, $history);
        self::assertSame('projected', $history[0]['status']);
        self::assertSame('build/first-run', $history[0]['branch']);
        self::assertSame(5, $history[0]['files_committed']);
    }

    public function test_history_ring_buffer_trims_to_20_entries_newest_first(): void
    {
        $buildPath = $this->makeTempDirWithFile();
        update_post_meta(42, '_mua_build_repo_url', 'https://github.com/owner/repo.git');
        update_post_meta(42, '_mua_github_token', self::validEncryptedToken());

        // Pre-seed 20 entries so the next one pushes the oldest out.
        $seed = [];
        for ($i = 0; $i < 20; $i++) {
            $seed[] = [
                'ts' => '2026-04-' . \str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . ' 00:00:00',
                'status' => 'projected',
                'branch' => 'build/seed-' . $i,
                'commit_sha' => '',
                'compare_url' => '',
                'files_committed' => 0,
                'error' => '',
                'duration_ms' => 0,
            ];
        }
        update_post_meta(42, '_mua_ship_history', $seed);

        TestableProjector::$nextResponse = [
            'branch'          => 'build/new-top',
            'commit_sha'      => 'newcommit',
            'compare_url'     => 'https://example.test/compare',
            'files_committed' => 7,
            'default_branch'  => 'main',
        ];

        TestableProjectBuildToGithub::handle(42, $buildPath, 'build/new-top', '[mua] bump');

        $history = $this->postMeta[42]['_mua_ship_history'];
        self::assertCount(20, $history, 'Ring buffer must cap at HISTORY_MAX (20).');
        self::assertSame('build/new-top', $history[0]['branch'], 'Newest entry at index 0.');
        self::assertSame('build/seed-0', $history[1]['branch'], 'Previous-head entry bumped to index 1.');
        // seed-19 (last one in the seed array) gets evicted when the new
        // entry makes the list 21 long and array_slice trims to 20.
        $branches = \array_column($history, 'branch');
        self::assertNotContains('build/seed-19', $branches);
    }

    public function test_failure_appends_history_entry_with_error(): void
    {
        $buildPath = $this->makeTempDirWithFile();
        update_post_meta(42, '_mua_build_repo_url', 'https://github.com/owner/repo.git');
        update_post_meta(42, '_mua_github_token', self::validEncryptedToken());

        TestableProjector::$shouldThrow = new \RuntimeException('network down');

        TestableProjectBuildToGithub::handle(42, $buildPath, 'build/fails', '[mua] boom');

        $history = $this->postMeta[42]['_mua_ship_history'] ?? [];
        self::assertCount(1, $history);
        self::assertSame('failed', $history[0]['status']);
        self::assertSame('build/fails', $history[0]['branch']);
        self::assertSame('network down', $history[0]['error']);
        self::assertSame('', $history[0]['commit_sha']);
    }

    /**
     * A valid SecureStorage v1 envelope so decrypt() round-trips to 'fake-token'.
     * Built at runtime since the key derivation is fixture-dependent.
     */
    private static function validEncryptedToken(): string
    {
        if (! \defined('MUA_SECURE_STORAGE_KEY')) {
            \define('MUA_SECURE_STORAGE_KEY', 'test-key-for-phpunit-only-never-use-in-prod');
        }
        return \MustUse\Pub\Support\SecureStorage::encrypt('fake-token');
    }

    private function makeTempDirWithFile(): string
    {
        $dir = \sys_get_temp_dir() . '/mua-job-test-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0755, true);
        \file_put_contents($dir . '/marker.txt', 'present');
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private static function deleteDir(string $dir): void
    {
        if (! \is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path) {
            /** @var \SplFileInfo $path */
            if ($path->isDir()) {
                @\rmdir($path->getPathname());
            } else {
                @\unlink($path->getPathname());
            }
        }
        @\rmdir($dir);
    }
}

/**
 * Test-only subclass that records the last project() call and returns the
 * stubbed response without touching the GitHub API.
 */
final class TestableProjector extends GitHubProjector
{
    /** @var array{branch: string, commit_sha: string, compare_url: string, files_committed: int, default_branch: string}|null */
    public static ?array $nextResponse = null;

    public static ?\Throwable $shouldThrow = null;

    public function project(string $buildPath, string $branch, string $commitMessage): array
    {
        if (self::$shouldThrow !== null) {
            throw self::$shouldThrow;
        }
        if (self::$nextResponse === null) {
            throw new \LogicException('TestableProjector::$nextResponse was not primed.');
        }
        return self::$nextResponse;
    }
}

/**
 * Test-only subclass that swaps in the fake projector.
 */
final class TestableProjectBuildToGithub extends ProjectBuildToGithub
{
    protected static function makeProjector(string $repoUrl, string $token): GitHubProjector
    {
        return new TestableProjector($repoUrl, $token);
    }
}
