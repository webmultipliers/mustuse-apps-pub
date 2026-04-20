<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Shell → pub HTTP client with stale-while-revalidate caching.
 *
 * Resolves endpoint URLs from the manifest's `endpoints` map. Bearer
 * auth using `MUA_APPKEY`. Never logs the Authorization header.
 *
 * SWR: fresh returns cached; stale returns cached + deferred refresh;
 * miss fetches synchronously; network failure returns the last cached
 * body (even past TTL) so templates don't render empty on transient
 * outages.
 */
class PubClient
{
    private const CACHE_PREFIX = 'pub:';

    public function resolveEndpoint(string $name): ?string
    {
        $manifest = $this->readManifest();
        $endpoints = $manifest['endpoints'] ?? [];
        if (! is_array($endpoints)) {
            return null;
        }
        $url = $endpoints[$name] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $pathParams Key/value map substituted into
     *                                          `{placeholder}` tokens in the
     *                                          endpoint URL itself. Used by
     *                                          routes like `/menus/{location}`
     *                                          or `/comments/{post_id}` where
     *                                          the identifier is part of the
     *                                          path, not a query string.
     * @return array{data: mixed, stale: bool, revision: ?string}
     */
    public function query(string $endpointName, array $params = [], int $ttl = 300, array $pathParams = []): array
    {
        $url = $this->resolveEndpoint($endpointName);
        if ($url === null) {
            return ['data' => null, 'stale' => false, 'revision' => null];
        }

        $url      = self::substitutePathParams($url, $pathParams);
        $params   = $this->resolveParams($params);
        $cacheKey = self::cacheKeyFor($endpointName, $params + $pathParams);
        $cached   = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['expires']) && $cached['expires'] > time()) {
            return [
                'data'     => $cached['data']     ?? null,
                'stale'    => false,
                'revision' => $cached['revision'] ?? null,
            ];
        }

        if (is_array($cached) && isset($cached['data'])) {
            $self = $this;
            defer(static fn () => $self->fetchAndStore($url, $params, $cacheKey, $ttl));
            return [
                'data'     => $cached['data'],
                'stale'    => true,
                'revision' => $cached['revision'] ?? null,
            ];
        }

        // Cache miss: synchronous fetch.
        try {
            $fresh = $this->fetchAndStore($url, $params, $cacheKey, $ttl);
            return [
                'data'     => $fresh['data'],
                'stale'    => false,
                'revision' => $fresh['revision'],
            ];
        } catch (\Throwable $e) {
            Log::warning('PubClient.query failed', $this->redactedContextFor($e, $url, $params));
            return ['data' => null, 'stale' => false, 'revision' => null];
        }
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $pathParams
     */
    public function invalidate(string $endpointName, array $params = [], array $pathParams = []): void
    {
        Cache::forget(self::cacheKeyFor($endpointName, $this->resolveParams($params) + $pathParams));
    }

    /**
     * Substitute `{placeholder}` tokens in an endpoint URL with values
     * from the supplied path-params map. Used for routes like
     * `/menus/{location}` where the identifier must land in the path
     * itself. Missing keys stay as literal `{key}` so the REST layer
     * returns a 404 the caller can surface rather than silently hitting
     * the wrong URL.
     *
     * @param array<string, string> $pathParams
     */
    private static function substitutePathParams(string $url, array $pathParams): string
    {
        if ($pathParams === []) {
            return $url;
        }
        foreach ($pathParams as $key => $value) {
            if (! \is_string($key) || $key === '') {
                continue;
            }
            $url = \str_replace('{' . $key . '}', \rawurlencode((string) $value), $url);
        }
        return $url;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{data: mixed, revision: ?string}
     */
    private function fetchAndStore(string $url, array $params, string $cacheKey, int $ttl): array
    {
        $response = Http::withHeaders([
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->appKey(),
            ])
            ->timeout(10)
            ->get($url, $params)
            ->throw();

        $revision = $response->header('X-MUA-Content-Revision') ?: null;
        $data     = $response->json();

        // Store ~4x TTL so the stale body is still available for SWR
        // fallbacks after the fresh window expires.
        Cache::put($cacheKey, [
            'data'     => $data,
            'revision' => $revision,
            'expires'  => time() + $ttl,
        ], max($ttl * 4, 3600));

        return ['data' => $data, 'revision' => $revision];
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(): array
    {
        $candidates = [
            storage_path('app/mua-manifest.json'),
            base_path('mua-manifest.json'),
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $raw = @file_get_contents($path);
                if ($raw !== false) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }
        return [];
    }

    /**
     * Drop empty / zero params and sort so the cache key is stable when
     * templates leave optional attributes unset. Mirrors the pub's key.
     *
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function resolveParams(array $params): array
    {
        $cleaned = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            // Drop 0 values — `category=0`, `author=0`, etc. are
            // "no filter" sentinels and would otherwise bust the cache
            // key by appearing as present.
            if ($v === 0 || $v === '0') {
                continue;
            }
            $cleaned[(string) $k] = $v;
        }
        ksort($cleaned);
        return $cleaned;
    }

    private function appKey(): string
    {
        $key = (string) env('MUA_APPKEY', '');
        if ($key === '') {
            throw new RuntimeException('MUA_APPKEY env missing — shell cannot authenticate to the pub.');
        }
        return $key;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function cacheKeyFor(string $endpointName, array $params): string
    {
        return self::CACHE_PREFIX . $endpointName . ':' . sha1(json_encode($params));
    }

    /**
     * Scrub sensitive values before Log::warning. Keeps the URL and
     * params but never the Authorization header.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function redactedContextFor(\Throwable $e, string $url, array $params): array
    {
        return [
            'endpoint' => $url,
            'params'   => $params,
            'error'    => $e->getMessage(),
            'class'    => get_class($e),
        ];
    }
}
