<?php

declare(strict_types=1);

namespace MustUse\Pub\Support;

/**
 * Projects a local directory as a single commit on a new branch of a GitHub
 * repository using the Git Data API. No shell_exec, no local git — pure HTTP.
 *
 * The branch is created from the repo's default branch but the commit's tree
 * is built from scratch (no base_tree), so the projected branch contains
 * exactly what's in the build path and nothing else. That keeps the build
 * branch a faithful snapshot of the assembled shell for Bifrost to consume.
 */
class GitHubProjector
{
    private const API_BASE = 'https://api.github.com';

    /**
     * Paths skipped during projection. `.env` is excluded even though Phase 12
     * will remove it from the assembler output entirely — defense in depth.
     */
    private const EXCLUDES = ['.env', '.env.backup', '.git', 'node_modules', 'vendor'];

    /**
     * Filenames excluded from change-detection hashing because they always
     * differ between ships on cosmetic/timestamp grounds, not actual content
     * changes (version code bumps, "Generated at {ts}" headers).
     */
    private const HASH_EXCLUDES = ['.env.example', 'README.md'];

    public function __construct(
        private readonly string $repoUrl,
        private readonly string $token,
    ) {}

    /**
     * @return array{branch: string, commit_sha: string, compare_url: string, files_committed: int, default_branch: string}
     */
    public function project(string $buildPath, string $branch, string $commitMessage): array
    {
        [$owner, $repo] = self::parseRepoUrl($this->repoUrl);

        $tree = $this->collectTree($buildPath);
        if ($tree === []) {
            throw new \RuntimeException('Nothing to project — build path is empty.');
        }

        $defaultBranch = $this->getDefaultBranch($owner, $repo);
        $baseSha       = $this->getRefSha($owner, $repo, $defaultBranch);

        $items = [];
        foreach ($tree as $relPath => $absPath) {
            $items[] = [
                'path' => $relPath,
                'mode' => self::fileMode($absPath),
                'type' => 'blob',
                'sha'  => $this->createBlob($owner, $repo, $absPath),
            ];
        }

        $treeSha = $this->createTree($owner, $repo, $items);
        // Empty-repo bootstrap: GitHub returns 409 "Git Repository is
        // empty." for any git-data read before the first commit. We
        // treat that as null here and emit a root commit (no parents).
        $parents   = $baseSha !== null ? [$baseSha] : [];
        $commitSha = $this->createCommit($owner, $repo, $commitMessage, $treeSha, $parents);
        $this->createRef($owner, $repo, "refs/heads/{$branch}", $commitSha);

        // When the repo was empty and we shipped to a non-default branch,
        // seed the default branch at the same root commit too. Without
        // this the repo is left half-initialised: the target branch has
        // the build, but the default branch ref doesn't exist, so
        // GitHub's compare view, PR workflows, and any downstream tool
        // that reads `default_branch` all 404. We tolerate a concurrent
        // ship having beaten us to the ref — that's still a successful
        // end state for our caller.
        if ($baseSha === null && $branch !== $defaultBranch) {
            try {
                $this->createRef($owner, $repo, "refs/heads/{$defaultBranch}", $commitSha);
            } catch (\RuntimeException $e) {
                if (! \str_contains($e->getMessage(), 'Reference already exists')) {
                    throw $e;
                }
            }
        }

        return [
            'branch'          => $branch,
            'commit_sha'      => $commitSha,
            'compare_url'     => "https://github.com/{$owner}/{$repo}/compare/{$defaultBranch}...{$branch}",
            'files_committed' => \count($items),
            'default_branch'  => $defaultBranch,
        ];
    }

    /**
     * Accepts https and ssh forms, with or without trailing `.git`.
     *
     * @return array{0: string, 1: string} [owner, repo]
     */
    public static function parseRepoUrl(string $url): array
    {
        $trimmed = \rtrim(\trim($url), '/');
        $trimmed = (string) \preg_replace('/\.git$/', '', $trimmed);

        if (\preg_match('#^git@github\.com:([^/]+)/([^/]+)$#', $trimmed, $m) === 1) {
            return [$m[1], $m[2]];
        }
        if (\preg_match('#^https?://github\.com/([^/]+)/([^/]+)$#', $trimmed, $m) === 1) {
            return [$m[1], $m[2]];
        }
        throw new \InvalidArgumentException("Cannot parse GitHub repo URL: {$url}");
    }

    /**
     * Walks $buildPath and returns relative→absolute paths for every file,
     * excluding anything matched by EXCLUDES. Keys are sorted for
     * deterministic commit trees.
     *
     * @return array<string, string>
     */
    public function collectTree(string $buildPath): array
    {
        $buildPath = \rtrim($buildPath, '/');
        if (! \is_dir($buildPath)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($buildPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $absPath = $file->getPathname();
            $relPath = \ltrim(\substr($absPath, \strlen($buildPath)), '/');

            if (self::isExcluded($relPath)) {
                continue;
            }
            $files[$relPath] = $absPath;
        }

        \ksort($files);
        return $files;
    }

    public static function isExcluded(string $relPath): bool
    {
        foreach (self::EXCLUDES as $pattern) {
            if ($relPath === $pattern || \str_starts_with($relPath, $pattern . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compute a deterministic sha256 over the build's content. Used by the
     * job to detect "nothing changed since last ship" and skip the GitHub
     * round-trip entirely (~50 API calls avoided).
     *
     * Algorithm: for each non-excluded file, hash the bytes. Emit
     * "{relPath}:{sha256}" lines, sort lexically, hash the joined list.
     * Identical content → identical digest across runs, regardless of
     * filesystem iteration order.
     *
     * Files whose contents are publisher-stamped (version code, projection
     * timestamp, README footer date) are excluded so cosmetic noise doesn't
     * mask true content-identity.
     */
    public function computeContentHash(string $buildPath): string
    {
        $tree = $this->collectTree($buildPath);
        $lines = [];
        foreach ($tree as $relPath => $absPath) {
            if (\in_array(\basename($relPath), self::HASH_EXCLUDES, true)) {
                continue;
            }
            $fileHash = @\hash_file('sha256', $absPath);
            if ($fileHash === false) {
                continue;
            }
            $lines[] = $relPath . ':' . $fileHash;
        }
        \sort($lines);
        return \hash('sha256', \implode("\n", $lines));
    }

    private static function fileMode(string $absPath): string
    {
        $perms = @\fileperms($absPath);
        return ($perms !== false && ($perms & 0100) !== 0) ? '100755' : '100644';
    }

    private function getDefaultBranch(string $owner, string $repo): string
    {
        $data = $this->api('GET', "/repos/{$owner}/{$repo}");
        $branch = $data['default_branch'] ?? null;
        if (! \is_string($branch) || $branch === '') {
            throw new \RuntimeException("Could not read default_branch for {$owner}/{$repo}.");
        }
        return $branch;
    }

    /**
     * Returns the head commit SHA of $branch, or null when the repo is
     * empty (no commits at all). GitHub surfaces the empty-repo state
     * as HTTP 409 with the message "Git Repository is empty." on any
     * git-data read — we translate that into a null so callers can
     * bootstrap a root commit.
     */
    private function getRefSha(string $owner, string $repo, string $branch): ?string
    {
        try {
            $data = $this->api('GET', "/repos/{$owner}/{$repo}/git/refs/heads/" . \rawurlencode($branch));
        } catch (\RuntimeException $e) {
            if (\str_contains($e->getMessage(), 'Git Repository is empty')) {
                return null;
            }
            throw $e;
        }
        $sha = $data['object']['sha'] ?? null;
        if (! \is_string($sha) || $sha === '') {
            throw new \RuntimeException("Could not read ref sha for {$branch} on {$owner}/{$repo}.");
        }
        return $sha;
    }

    private function createBlob(string $owner, string $repo, string $absPath): string
    {
        $content = @\file_get_contents($absPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$absPath}");
        }
        $data = $this->api('POST', "/repos/{$owner}/{$repo}/git/blobs", [
            'content'  => \base64_encode($content),
            'encoding' => 'base64',
        ]);
        $sha = $data['sha'] ?? null;
        if (! \is_string($sha) || $sha === '') {
            throw new \RuntimeException("Blob create returned no sha for {$absPath}.");
        }
        return $sha;
    }

    /**
     * @param array<int, array{path: string, mode: string, type: string, sha: string}> $items
     */
    private function createTree(string $owner, string $repo, array $items): string
    {
        $data = $this->api('POST', "/repos/{$owner}/{$repo}/git/trees", [
            'tree' => $items,
        ]);
        $sha = $data['sha'] ?? null;
        if (! \is_string($sha) || $sha === '') {
            throw new \RuntimeException('Tree create returned no sha.');
        }
        return $sha;
    }

    /**
     * @param array<int, string> $parents
     */
    private function createCommit(string $owner, string $repo, string $message, string $treeSha, array $parents): string
    {
        $data = $this->api('POST', "/repos/{$owner}/{$repo}/git/commits", [
            'message' => $message,
            'tree'    => $treeSha,
            'parents' => $parents,
        ]);
        $sha = $data['sha'] ?? null;
        if (! \is_string($sha) || $sha === '') {
            throw new \RuntimeException('Commit create returned no sha.');
        }
        return $sha;
    }

    private function createRef(string $owner, string $repo, string $ref, string $sha): void
    {
        $this->api('POST', "/repos/{$owner}/{$repo}/git/refs", [
            'ref' => $ref,
            'sha' => $sha,
        ]);
    }

    /**
     * Centralised HTTP entry point so tests can override and injected
     * credentials never leak via wp_remote_* logging.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function api(string $method, string $path, ?array $body = null): array
    {
        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization'        => 'Bearer ' . $this->token,
                'Accept'               => 'application/vnd.github+json',
                'User-Agent'           => 'mustuse-apps-pub',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
            'timeout' => 30,
        ];

        if ($body !== null) {
            $encoded = wp_json_encode($body);
            if ($encoded === false) {
                throw new \RuntimeException("Failed to JSON-encode GitHub API body for {$method} {$path}.");
            }
            $args['body'] = $encoded;
            $args['headers']['Content-Type'] = 'application/json';
        }

        // Retry transient failures (network error, 429, 5xx) twice with
        // exponential backoff. 4xx responses are authoritative — no retry.
        $attempts  = 0;
        $maxRetry  = 2;
        $lastError = '';

        while (true) {
            $attempts++;
            $response = wp_remote_request(self::API_BASE . $path, $args);

            if (is_wp_error($response)) {
                $lastError = $response->get_error_message();
                if ($attempts > $maxRetry) {
                    throw new \RuntimeException('GitHub API request failed: ' . $lastError);
                }
                $this->sleepBeforeRetry($attempts);
                continue;
            }

            $code    = (int) wp_remote_retrieve_response_code($response);
            $bodyRaw = (string) wp_remote_retrieve_body($response);
            $decoded = \json_decode($bodyRaw, true);

            if ($code < 400) {
                return \is_array($decoded) ? $decoded : [];
            }

            $msg = \is_array($decoded) && isset($decoded['message']) ? (string) $decoded['message'] : 'HTTP ' . $code;
            $isTransient = $code === 429 || ($code >= 500 && $code < 600);

            if ($isTransient && $attempts <= $maxRetry) {
                $lastError = "{$code}: {$msg}";
                $this->sleepBeforeRetry($attempts);
                continue;
            }

            throw new \RuntimeException("GitHub API {$method} {$path} failed ({$code}): {$msg}");
        }
    }

    /**
     * Exponential backoff: 1s, 2s, 4s. Overridable for tests.
     */
    protected function sleepBeforeRetry(int $attempt): void
    {
        \sleep(2 ** ($attempt - 1));
    }
}
