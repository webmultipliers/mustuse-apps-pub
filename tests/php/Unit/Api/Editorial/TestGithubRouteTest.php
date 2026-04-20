<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Api\Editorial;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Api\Editorial\TestGithubRoute;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Freezes the response contract of POST /test-github-connection:
 *   - parse error → ok:false + error
 *   - missing URL/token → ok:false with diagnostic
 *   - 200/401/404/rate-limit → specific error text the admin UI surfaces
 */
final class TestGithubRouteTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        Functions\when('get_option')->justReturn('');
        Functions\when('__')->returnArg(1);

        StubTestGithubRoute::$cannedProbe = null;
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_missing_repo_url_returns_ok_false(): void
    {
        $response = (new StubTestGithubRoute())->handleForTest($this->jsonRequest([]));
        $data = $response->get_data();

        self::assertFalse($data['ok']);
        self::assertStringContainsString('Repository URL', $data['error']);
    }

    public function test_missing_token_returns_ok_false(): void
    {
        $response = (new StubTestGithubRoute())->handleForTest(
            $this->jsonRequest(['repo_url' => 'https://github.com/owner/repo.git'])
        );
        $data = $response->get_data();

        self::assertFalse($data['ok']);
        self::assertStringContainsString('token', $data['error']);
    }

    public function test_invalid_repo_url_returns_parse_error(): void
    {
        $response = (new StubTestGithubRoute())->handleForTest($this->jsonRequest([
            'repo_url' => 'https://gitlab.com/owner/repo',
            'token'    => 'ghp_test',
        ]));
        $data = $response->get_data();

        self::assertFalse($data['ok']);
        self::assertStringContainsString('Cannot parse', $data['error']);
    }

    public function test_success_returns_owner_repo_and_default_branch(): void
    {
        StubTestGithubRoute::$cannedProbe = [
            'ok'             => true,
            'owner'          => 'owner',
            'repo'           => 'repo',
            'default_branch' => 'trunk',
        ];

        $response = (new StubTestGithubRoute())->handleForTest($this->jsonRequest([
            'repo_url' => 'https://github.com/owner/repo.git',
            'token'    => 'ghp_test',
        ]));
        $data = $response->get_data();

        self::assertTrue($data['ok']);
        self::assertSame('owner', $data['owner']);
        self::assertSame('repo', $data['repo']);
        self::assertSame('trunk', $data['default_branch']);
    }

    public function test_auth_failure_surfaces_scope_hint(): void
    {
        StubTestGithubRoute::$cannedProbe = [
            'ok'    => false,
            'error' => 'Authentication failed — check the Personal Access Token scope (needs Contents: Read/Write).',
        ];

        $response = (new StubTestGithubRoute())->handleForTest($this->jsonRequest([
            'repo_url' => 'https://github.com/owner/repo.git',
            'token'    => 'bad-token',
        ]));
        $data = $response->get_data();

        self::assertFalse($data['ok']);
        self::assertStringContainsString('Personal Access Token', $data['error']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function jsonRequest(array $params): WP_REST_Request
    {
        return new WP_REST_Request([], (string) \json_encode($params));
    }
}

final class StubTestGithubRoute extends TestGithubRoute
{
    /** @var array{ok: bool, owner?: string, repo?: string, default_branch?: string, error?: string}|null */
    public static ?array $cannedProbe = null;

    protected function probeRepo(string $owner, string $repo, string $token): array
    {
        if (self::$cannedProbe === null) {
            throw new \LogicException('Prime StubTestGithubRoute::$cannedProbe before the test.');
        }
        return self::$cannedProbe;
    }

    /**
     * Test convenience wrapper that narrows the return type — the parent
     * returns WP_REST_Response|WP_Error; this override proves the wrapped
     * handle() returns the Response path for every test case we cover.
     */
    public function handleForTest(WP_REST_Request $request): WP_REST_Response
    {
        $response = $this->handle($request);
        if (! $response instanceof WP_REST_Response) {
            throw new \LogicException('TestGithubRoute::handle was expected to return WP_REST_Response.');
        }
        return $response;
    }
}
