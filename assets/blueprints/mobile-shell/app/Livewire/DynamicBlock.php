<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Support\PubClient;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Runtime wrapper for any block whose `native.json` declares a
 * `dataSource`. Fetches through PubClient on mount, re-renders the
 * block's mobile.blade.php with `$data` bound, and re-fetches on
 * `block-refresh` broadcasts (pull-to-refresh).
 */
class DynamicBlock extends Component
{
    /** The WP block slug, e.g. "article-list". */
    public string $slug = '';

    /** @var array<string, mixed> Attributes straight from the manifest. */
    public array $attributes = [];

    /** @var array<string, mixed>|null Route context (post/term/author) if any. */
    public ?array $context = null;

    /** @var array<string, mixed>|null The fetched body (e.g. `{items, pagination, app}`). */
    public ?array $data = null;

    public bool $stale = false;

    public bool $loading = true;

    public function mount(string $slug, array $attributes = [], ?array $context = null): void
    {
        $this->slug       = $slug;
        $this->attributes = $attributes;
        $this->context    = $context;
        $this->hydrate();
    }

    #[On('block-refresh')]
    public function refresh(): void
    {
        $descriptor = $this->resolveDataSource();
        if ($descriptor === null) {
            return;
        }
        $params     = $this->resolvedParams(is_array($descriptor['params']      ?? null) ? $descriptor['params']      : []);
        $pathParams = $this->resolvedParams(is_array($descriptor['path_params'] ?? null) ? $descriptor['path_params'] : []);
        app(PubClient::class)->invalidate(
            (string) ($descriptor['endpoint'] ?? ''),
            $params,
            $pathParams,
        );
        $this->hydrate();
    }

    private function hydrate(): void
    {
        $descriptor = $this->resolveDataSource();
        if ($descriptor === null) {
            $this->loading = false;
            return;
        }

        $params     = $this->resolvedParams(is_array($descriptor['params']      ?? null) ? $descriptor['params']      : []);
        $pathParams = $this->resolvedParams(is_array($descriptor['path_params'] ?? null) ? $descriptor['path_params'] : []);

        $result = app(PubClient::class)->query(
            (string) ($descriptor['endpoint'] ?? 'content'),
            $params,
            (int) ($descriptor['ttl'] ?? 300),
            $pathParams,
        );

        $this->data    = is_array($result['data']) ? $result['data'] : null;
        $this->stale   = (bool) $result['stale'];
        $this->loading = false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveDataSource(): ?array
    {
        if ($this->slug === '' || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $this->slug)) {
            return null;
        }
        $path = public_path('blocks/' . $this->slug . '/native.json');
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }
        $ds = $decoded['dataSource'] ?? null;
        return is_array($ds) ? $ds : null;
    }

    /**
     * Interpolate `{{ attributes.x }}` / `{{ context.post.y }}` tokens
     * in the native.json param template. Missing references collapse
     * to empty string and are pruned by PubClient::resolveParams().
     *
     * @param  array<string, mixed> $template
     * @return array<string, mixed>
     */
    private function resolvedParams(array $template): array
    {
        $out = [];
        foreach ($template as $key => $raw) {
            $out[(string) $key] = $this->interpolate((string) $raw);
        }
        return $out;
    }

    private function interpolate(string $template): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $m): string {
                [$scope, $field] = array_pad(explode('.', $m[1], 2), 2, '');
                return match ($scope) {
                    'attributes' => (string) ($this->attributes[$field] ?? ''),
                    'context'    => (string) data_get($this->context, $field, ''),
                    default      => '',
                };
            },
            $template,
        );
    }

    public function render()
    {
        return view('livewire.dynamic-block', [
            'slug'       => $this->slug,
            'attributes' => $this->attributes,
            'context'    => $this->context,
            'data'       => $this->data,
            'stale'      => $this->stale,
            'loading'    => $this->loading,
        ]);
    }
}
