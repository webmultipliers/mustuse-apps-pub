<?php

declare(strict_types=1);

namespace MustUse\Pub\Api\Editorial;

use MustUse\Pub\Support\GitHubProjector;
use MustUse\Pub\Support\SecureStorage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /test-github-connection — verifies the configured or supplied GitHub
 * PAT + repo URL by hitting `GET /repos/{owner}/{repo}`. Returns the repo's
 * default branch and name on success so the publisher knows the credential
 * actually works before wasting a ship attempt on a bad token.
 *
 * Auth: site administrator. Touches the GitHub PAT, so this is always
 * an admin-level operation.
 */
class TestGithubRoute
{
    private const NAMESPACE = 'mustuse-apps-pub/v1';

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/test-github-connection', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [$this, 'authorize'],
            'args'                => [
                'repo_url' => [
                    'type'     => 'string',
                    'required' => false,
                ],
                'token' => [
                    'type'     => 'string',
                    'required' => false,
                ],
            ],
        ]);
    }

    public function authorize(WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body  = $request->get_json_params() ?: [];
        $appId = isset($body['app_id']) ? (int) $body['app_id'] : 0;

        // Prefer values from the POST body (publisher is typing in the form);
        // fall back to stored per-app meta for "test saved config" flow.
        $repoUrl = isset($body['repo_url']) && $body['repo_url'] !== ''
            ? (string) $body['repo_url']
            : ($appId > 0 ? (string) get_post_meta($appId, '_mua_build_repo_url', true) : '');
        $token = isset($body['token']) && $body['token'] !== ''
            ? (string) $body['token']
            : $this->resolveStoredToken($appId);

        if ($repoUrl === '') {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => __('Repository URL is required.', 'mustuse-apps-pub'),
            ], 200);
        }

        if ($token === '') {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => __('GitHub token is required (no stored token available).', 'mustuse-apps-pub'),
            ], 200);
        }

        try {
            [$owner, $repo] = GitHubProjector::parseRepoUrl($repoUrl);
        } catch (\InvalidArgumentException $e) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 200);
        }

        $result = $this->probeRepo($owner, $repo, $token);
        return new WP_REST_Response($result, 200);
    }

    private function resolveStoredToken(int $appId): string
    {
        if ($appId <= 0) {
            return '';
        }
        $stored = (string) get_post_meta($appId, '_mua_github_token', true);
        if ($stored === '') {
            return '';
        }
        $decrypted = SecureStorage::decrypt($stored);
        return \is_string($decrypted) ? $decrypted : '';
    }

    /**
     * @return array{ok: bool, owner?: string, repo?: string, default_branch?: string, error?: string}
     */
    protected function probeRepo(string $owner, string $repo, string $token): array
    {
        $response = wp_remote_request('https://api.github.com/repos/' . \rawurlencode($owner) . '/' . \rawurlencode($repo), [
            'method'  => 'GET',
            'timeout' => 15,
            'headers' => [
                'Authorization'        => 'Bearer ' . $token,
                'Accept'               => 'application/vnd.github+json',
                'User-Agent'           => 'mustuse-apps-pub',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'ok'    => false,
                'error' => 'Network error: ' . $response->get_error_message(),
            ];
        }

        $code    = (int) wp_remote_retrieve_response_code($response);
        $bodyRaw = (string) wp_remote_retrieve_body($response);
        $decoded = \json_decode($bodyRaw, true);

        if ($code === 200 && \is_array($decoded)) {
            return [
                'ok'             => true,
                'owner'          => $owner,
                'repo'           => $repo,
                'default_branch' => (string) ($decoded['default_branch'] ?? 'main'),
            ];
        }

        $msg = match (true) {
            $code === 401 => __('Authentication failed — check the Personal Access Token scope (needs Contents: Read/Write).', 'mustuse-apps-pub'),
            $code === 404 => __('Repository not found or token has no access to it.', 'mustuse-apps-pub'),
            $code === 403 => __('Rate-limited or forbidden by GitHub.', 'mustuse-apps-pub'),
            default       => \sprintf(
                /* translators: 1: HTTP status code, 2: raw GitHub error message */
                __('GitHub returned HTTP %1$d: %2$s', 'mustuse-apps-pub'),
                $code,
                \is_array($decoded) && isset($decoded['message']) ? (string) $decoded['message'] : 'Unknown error'
            ),
        };

        return [
            'ok'    => false,
            'error' => $msg,
        ];
    }
}
