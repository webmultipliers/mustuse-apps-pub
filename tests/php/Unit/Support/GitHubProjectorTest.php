<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Support;

use Brain\Monkey;
use MustUse\Pub\Support\GitHubProjector;
use PHPUnit\Framework\TestCase;

final class GitHubProjectorTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::deleteDir($dir);
        }
        $this->tempDirs = [];

        Monkey\tearDown();
    }

    public function test_parse_accepts_https_url(): void
    {
        self::assertSame(['mustuse', 'mobile-shell'], GitHubProjector::parseRepoUrl('https://github.com/mustuse/mobile-shell'));
    }

    public function test_parse_accepts_https_url_with_git_suffix(): void
    {
        self::assertSame(['mustuse', 'mobile-shell'], GitHubProjector::parseRepoUrl('https://github.com/mustuse/mobile-shell.git'));
    }

    public function test_parse_accepts_ssh_url(): void
    {
        self::assertSame(['foo', 'bar'], GitHubProjector::parseRepoUrl('git@github.com:foo/bar.git'));
    }

    public function test_parse_tolerates_trailing_slash(): void
    {
        self::assertSame(['a', 'b'], GitHubProjector::parseRepoUrl('https://github.com/a/b/'));
    }

    public function test_parse_rejects_non_github_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GitHubProjector::parseRepoUrl('https://gitlab.com/owner/repo.git');
    }

    public function test_parse_rejects_garbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GitHubProjector::parseRepoUrl('not-a-url');
    }

    public function test_exclude_matches_exact_and_prefix(): void
    {
        self::assertTrue(GitHubProjector::isExcluded('.env'));
        self::assertTrue(GitHubProjector::isExcluded('vendor/autoload.php'));
        self::assertTrue(GitHubProjector::isExcluded('node_modules/some/deep/file.js'));
        self::assertTrue(GitHubProjector::isExcluded('.git/HEAD'));

        self::assertFalse(GitHubProjector::isExcluded('env.example'));
        self::assertFalse(GitHubProjector::isExcluded('src/vendor-wrapper.php'));
        self::assertFalse(GitHubProjector::isExcluded('composer.json'));
    }

    public function test_collect_tree_returns_sorted_relative_paths_and_skips_excluded(): void
    {
        $root = $this->makeTempDir();
        \file_put_contents($root . '/composer.json', '{}');
        \mkdir($root . '/app/Http', 0755, true);
        \file_put_contents($root . '/app/Http/Controller.php', '<?php');
        \mkdir($root . '/vendor', 0755, true);
        \file_put_contents($root . '/vendor/autoload.php', '<?php');
        \file_put_contents($root . '/.env', 'SECRET=1');

        $projector = new GitHubProjector('https://github.com/o/r.git', 'token');
        $tree = $projector->collectTree($root);

        self::assertSame([
            'app/Http/Controller.php',
            'composer.json',
        ], \array_keys($tree));

        foreach ($tree as $rel => $abs) {
            self::assertNotSame('', $rel);
            self::assertStringEndsWith('/' . $rel, \str_replace(\DIRECTORY_SEPARATOR, '/', $abs));
        }
    }

    public function test_collect_tree_returns_empty_for_missing_dir(): void
    {
        $projector = new GitHubProjector('https://github.com/o/r.git', 'token');
        self::assertSame([], $projector->collectTree('/nonexistent/path/xyz'));
    }

    public function test_compute_content_hash_is_stable_for_identical_trees(): void
    {
        $a = $this->makeTempDir();
        $b = $this->makeTempDir();
        \file_put_contents($a . '/hero.blade.php', '<div>hero</div>');
        \file_put_contents($b . '/hero.blade.php', '<div>hero</div>');
        \mkdir($a . '/config', 0755, true);
        \mkdir($b . '/config', 0755, true);
        \file_put_contents($a . '/config/app.php', "<?php return [];\n");
        \file_put_contents($b . '/config/app.php', "<?php return [];\n");

        $projector = new GitHubProjector('https://github.com/o/r.git', 't');

        self::assertSame(
            $projector->computeContentHash($a),
            $projector->computeContentHash($b),
            'Identical content must produce identical digests across independent trees.',
        );
    }

    public function test_compute_content_hash_changes_when_any_file_changes(): void
    {
        $root = $this->makeTempDir();
        \file_put_contents($root . '/hero.blade.php', 'v1');
        $projector = new GitHubProjector('https://github.com/o/r.git', 't');
        $before = $projector->computeContentHash($root);

        \file_put_contents($root . '/hero.blade.php', 'v2');
        $after = $projector->computeContentHash($root);

        self::assertNotSame($before, $after);
    }

    public function test_compute_content_hash_ignores_env_example_and_readme_timestamps(): void
    {
        $root = $this->makeTempDir();
        \file_put_contents($root . '/hero.blade.php', 'stable');
        \file_put_contents($root . '/.env.example', "# Generated at 2026-01-01\nKEY=value\n");
        \file_put_contents($root . '/README.md', '# Projected at 2026-01-01');

        $projector = new GitHubProjector('https://github.com/o/r.git', 't');
        $before = $projector->computeContentHash($root);

        // Only the excluded files change — hash must stay identical.
        \file_put_contents($root . '/.env.example', "# Generated at 2026-12-31\nKEY=value\n");
        \file_put_contents($root . '/README.md', '# Projected at 2026-12-31');
        $after = $projector->computeContentHash($root);

        self::assertSame($before, $after, '.env.example and README.md timestamp changes must not affect the content hash.');
    }

    public function test_project_uses_default_branch_head_as_commit_parent(): void
    {
        $root = $this->makeTempDir();
        \file_put_contents($root . '/README.md', 'hi');

        $projector = new class ('https://github.com/o/r', 't') extends GitHubProjector {
            /** @var list<array{method: string, path: string, body: ?array}> */
            public array $calls = [];
            protected function api(string $method, string $path, ?array $body = null): array
            {
                $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];
                return match (true) {
                    \str_ends_with($path, '/r') && $method === 'GET'                 => ['default_branch' => 'development'],
                    \str_contains($path, '/git/refs/heads/development')              => ['object' => ['sha' => 'BASE_SHA']],
                    \str_contains($path, '/git/blobs')                               => ['sha' => 'BLOB_SHA'],
                    \str_contains($path, '/git/trees')                               => ['sha' => 'TREE_SHA'],
                    \str_contains($path, '/git/commits')                             => ['sha' => 'COMMIT_SHA'],
                    \str_contains($path, '/git/refs') && $method === 'POST'          => [],
                    default                                                          => [],
                };
            }
        };

        $result = $projector->project($root, 'ship/2025-04-19', 'Initial');

        self::assertSame('COMMIT_SHA', $result['commit_sha']);
        $commitCall = self::firstCall($projector->calls, 'POST', '/git/commits');
        self::assertSame(['BASE_SHA'], $commitCall['body']['parents'] ?? null);
    }

    public function test_project_emits_root_commit_when_target_repo_is_empty(): void
    {
        $root = $this->makeTempDir();
        \file_put_contents($root . '/README.md', 'hi');

        $projector = new class ('https://github.com/o/r', 't') extends GitHubProjector {
            /** @var list<array{method: string, path: string, body: ?array}> */
            public array $calls = [];
            protected function api(string $method, string $path, ?array $body = null): array
            {
                $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];
                if (\str_ends_with($path, '/r') && $method === 'GET') {
                    return ['default_branch' => 'development'];
                }
                // GitHub signals an uninitialised repo with HTTP 409
                // "Git Repository is empty." on any git-data read. The
                // parent class's api() throws RuntimeException with the
                // 409 message — mirror that here so the null-on-409
                // path in getRefSha() executes.
                if (\str_contains($path, '/git/refs/heads/development') && $method === 'GET') {
                    throw new \RuntimeException('GitHub API GET ' . $path . ' failed (409): Git Repository is empty.');
                }
                return match (true) {
                    \str_contains($path, '/git/blobs')                      => ['sha' => 'BLOB_SHA'],
                    \str_contains($path, '/git/trees')                      => ['sha' => 'TREE_SHA'],
                    \str_contains($path, '/git/commits')                    => ['sha' => 'ROOT_SHA'],
                    \str_contains($path, '/git/refs') && $method === 'POST' => [],
                    default                                                 => [],
                };
            }
        };

        $result = $projector->project($root, 'development', 'First ship');

        self::assertSame('ROOT_SHA', $result['commit_sha']);
        $commitCall = self::firstCall($projector->calls, 'POST', '/git/commits');
        self::assertSame([], $commitCall['body']['parents'] ?? null, 'Empty-repo projections must emit a root commit with no parents.');
    }

    public function test_project_seeds_default_branch_when_empty_repo_is_shipped_to_non_default(): void
    {
        $root = $this->makeTempDir();
        \file_put_contents($root . '/README.md', 'hi');

        $projector = new class ('https://github.com/o/r', 't') extends GitHubProjector {
            /** @var list<array{method: string, path: string, body: ?array}> */
            public array $calls = [];
            protected function api(string $method, string $path, ?array $body = null): array
            {
                $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];
                if (\str_ends_with($path, '/r') && $method === 'GET') {
                    return ['default_branch' => 'main'];
                }
                if (\str_contains($path, '/git/refs/heads/main') && $method === 'GET') {
                    throw new \RuntimeException('GitHub API GET ' . $path . ' failed (409): Git Repository is empty.');
                }
                return match (true) {
                    \str_contains($path, '/git/blobs')                      => ['sha' => 'BLOB_SHA'],
                    \str_contains($path, '/git/trees')                      => ['sha' => 'TREE_SHA'],
                    \str_contains($path, '/git/commits')                    => ['sha' => 'ROOT_SHA'],
                    \str_contains($path, '/git/refs') && $method === 'POST' => [],
                    default                                                 => [],
                };
            }
        };

        $projector->project($root, 'feature/kitchen-sink', 'First ship');

        $refCalls = \array_values(\array_filter(
            $projector->calls,
            static fn (array $c): bool => $c['method'] === 'POST' && \str_contains($c['path'], '/git/refs'),
        ));
        $refs = \array_map(static fn (array $c): string => (string) ($c['body']['ref'] ?? ''), $refCalls);

        self::assertContains('refs/heads/feature/kitchen-sink', $refs);
        self::assertContains('refs/heads/main',                 $refs, 'Empty repo + off-default ship must also seed the default branch.');

        foreach ($refCalls as $c) {
            self::assertSame('ROOT_SHA', $c['body']['sha'] ?? null, 'Both refs must point at the same root commit.');
        }
    }

    public function test_project_tolerates_default_branch_ref_already_existing_on_seed(): void
    {
        $root = $this->makeTempDir();
        \file_put_contents($root . '/README.md', 'hi');

        $projector = new class ('https://github.com/o/r', 't') extends GitHubProjector {
            /** @var list<array{method: string, path: string, body: ?array}> */
            public array $calls = [];
            protected function api(string $method, string $path, ?array $body = null): array
            {
                $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];
                if (\str_ends_with($path, '/r') && $method === 'GET') {
                    return ['default_branch' => 'main'];
                }
                if (\str_contains($path, '/git/refs/heads/main') && $method === 'GET') {
                    throw new \RuntimeException('GitHub API GET ' . $path . ' failed (409): Git Repository is empty.');
                }
                if ($method === 'POST' && \str_contains($path, '/git/refs')) {
                    $ref = (string) ($body['ref'] ?? '');
                    if ($ref === 'refs/heads/main') {
                        // Simulate a concurrent ship that seeded main
                        // between our 409 read and our seed attempt.
                        throw new \RuntimeException('GitHub API POST ' . $path . ' failed (422): Reference already exists');
                    }
                    return [];
                }
                return match (true) {
                    \str_contains($path, '/git/blobs')   => ['sha' => 'BLOB_SHA'],
                    \str_contains($path, '/git/trees')   => ['sha' => 'TREE_SHA'],
                    \str_contains($path, '/git/commits') => ['sha' => 'ROOT_SHA'],
                    default                              => [],
                };
            }
        };

        // Must not throw — the target branch was created successfully,
        // the default was already there, that's a fine end state.
        $result = $projector->project($root, 'feature/kitchen-sink', 'First ship');
        self::assertSame('ROOT_SHA', $result['commit_sha']);
    }

    /**
     * @param list<array{method: string, path: string, body: ?array}> $calls
     * @return array{method: string, path: string, body: ?array}
     */
    private static function firstCall(array $calls, string $method, string $pathFragment): array
    {
        foreach ($calls as $c) {
            if ($c['method'] === $method && \str_contains($c['path'], $pathFragment)) {
                return $c;
            }
        }
        self::fail("No {$method} call matching {$pathFragment} was recorded.");
    }

    private function makeTempDir(): string
    {
        $dir = \sys_get_temp_dir() . '/mua-gh-test-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0755, true);
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
