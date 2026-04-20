<?php

declare(strict_types=1);

namespace MustUse\Pub\Jobs;

use MustUse\Pub\Support\GitHubProjector;
use MustUse\Pub\Support\SecureStorage;

/**
 * Action Scheduler handler: pushes an assembled build path to GitHub as a new
 * branch+commit and updates the app's build-status post meta so
 * BuildStatusRoute can surface progress to the admin UI.
 *
 * Reads the repo URL and PAT from per-app meta (`_mua_build_repo_url` and
 * `_mua_github_token`, SecureStorage-encrypted). Both are configured on the
 * app's Distribution tab.
 *
 * Change detection: before calling the projector, computes a content hash
 * over the build path and compares against the last successful projection's
 * hash. If identical, skips the GitHub round-trip entirely (~50 API calls
 * avoided) and records an `unchanged` history entry instead.
 *
 * Ship history: every run appends a row to the `_mua_ship_history` ring
 * buffer (max 20) so publishers can see their full ship log on the
 * Distribution tab — regardless of outcome (projected / unchanged / failed).
 *
 * Always wipes $buildPath in the finally block so transient local state
 * never survives a run.
 */
class ProjectBuildToGithub
{
    public const ACTION = 'mua_project_build_to_github';

    /** Max entries retained in `_mua_ship_history` (newest first). */
    public const HISTORY_MAX = 20;

    public static function register(): void
    {
        add_action(self::ACTION, [self::class, 'handle'], 10, 4);
    }

    public static function handle(int $appId, string $buildPath, string $branch, string $commitMessage): void
    {
        $startedAt = \microtime(true);
        update_post_meta($appId, '_mua_last_build_status', 'projecting');

        try {
            $repoUrl = (string) get_post_meta($appId, '_mua_build_repo_url', true);
            if ($repoUrl === '') {
                throw new \RuntimeException('Build repository URL is not configured for this app.');
            }

            $token = self::resolveToken($appId);
            if ($token === null) {
                throw new \RuntimeException('GitHub token is not configured or cannot be decrypted for this app.');
            }

            $projector = static::makeProjector($repoUrl, $token);

            // Change detection: local sha256 over build contents.
            $contentHash = $projector->computeContentHash($buildPath);
            $lastHash    = (string) get_post_meta($appId, '_mua_last_projection_hash', true);

            if ($lastHash !== '' && $contentHash === $lastHash) {
                self::handleUnchanged($appId, $startedAt);
                return;
            }

            $result = $projector->project($buildPath, $branch, $commitMessage);

            update_post_meta($appId, '_mua_last_build_status', 'projected');
            update_post_meta($appId, '_mua_last_build_branch', $result['branch']);
            update_post_meta($appId, '_mua_last_build_commit', $result['commit_sha']);
            update_post_meta($appId, '_mua_last_build_compare_url', $result['compare_url']);
            update_post_meta($appId, '_mua_last_build_at', current_time('mysql', true));
            update_post_meta($appId, '_mua_last_projection_hash', $contentHash);
            delete_post_meta($appId, '_mua_last_build_error');

            self::appendHistory($appId, [
                'ts'              => current_time('mysql', true),
                'status'          => 'projected',
                'branch'          => $result['branch'],
                'commit_sha'      => $result['commit_sha'],
                'compare_url'     => $result['compare_url'],
                'files_committed' => $result['files_committed'],
                'error'           => '',
                'duration_ms'     => self::elapsedMs($startedAt),
            ]);
        } catch (\Throwable $e) {
            update_post_meta($appId, '_mua_last_build_status', 'failed');
            update_post_meta($appId, '_mua_last_build_error', $e->getMessage());
            update_post_meta($appId, '_mua_last_build_at', current_time('mysql', true));

            self::appendHistory($appId, [
                'ts'              => current_time('mysql', true),
                'status'          => 'failed',
                'branch'          => $branch,
                'commit_sha'      => '',
                'compare_url'     => '',
                'files_committed' => 0,
                'error'           => $e->getMessage(),
                'duration_ms'     => self::elapsedMs($startedAt),
            ]);
        } finally {
            self::deleteDirectory($buildPath);
        }
    }

    /**
     * Record an "unchanged" outcome: same content as last successful ship,
     * no GitHub round-trip, history row added pointing to the previous
     * branch so the publisher can still find the compare URL.
     */
    private static function handleUnchanged(int $appId, float $startedAt): void
    {
        $lastBranch     = (string) get_post_meta($appId, '_mua_last_build_branch', true);
        $lastCommit     = (string) get_post_meta($appId, '_mua_last_build_commit', true);
        $lastCompareUrl = (string) get_post_meta($appId, '_mua_last_build_compare_url', true);

        update_post_meta($appId, '_mua_last_build_status', 'unchanged');
        update_post_meta($appId, '_mua_last_build_at', current_time('mysql', true));
        delete_post_meta($appId, '_mua_last_build_error');

        self::appendHistory($appId, [
            'ts'              => current_time('mysql', true),
            'status'          => 'unchanged',
            'branch'          => $lastBranch,
            'commit_sha'      => $lastCommit,
            'compare_url'     => $lastCompareUrl,
            'files_committed' => 0,
            'error'           => '',
            'duration_ms'     => self::elapsedMs($startedAt),
        ]);
    }

    /**
     * Prepend a ship entry to the per-app history and trim to HISTORY_MAX.
     *
     * @param array{ts: string, status: string, branch: string, commit_sha: string, compare_url: string, files_committed: int, error: string, duration_ms: int} $entry
     */
    private static function appendHistory(int $appId, array $entry): void
    {
        $raw = get_post_meta($appId, '_mua_ship_history', true);
        $history = \is_array($raw) ? $raw : [];

        \array_unshift($history, $entry);
        if (\count($history) > self::HISTORY_MAX) {
            $history = \array_slice($history, 0, self::HISTORY_MAX);
        }

        update_post_meta($appId, '_mua_ship_history', $history);
    }

    private static function elapsedMs(float $startedAt): int
    {
        return (int) ((\microtime(true) - $startedAt) * 1000);
    }

    protected static function makeProjector(string $repoUrl, string $token): GitHubProjector
    {
        return new GitHubProjector($repoUrl, $token);
    }

    private static function resolveToken(int $appId): ?string
    {
        $stored = (string) get_post_meta($appId, '_mua_github_token', true);
        if ($stored === '') {
            return null;
        }
        return SecureStorage::decrypt($stored);
    }

    private static function deleteDirectory(string $dir): void
    {
        if ($dir === '' || ! \is_dir($dir)) {
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
