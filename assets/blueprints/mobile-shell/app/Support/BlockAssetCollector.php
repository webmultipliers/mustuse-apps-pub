<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Walks a rendered screen's block tree and collects the CSS / JS each
 * block contributes. Used by the root layout (components/layouts/app.blade.php)
 * to inline exactly the assets the current screen needs — no monolithic
 * "shell.css", no unused bytes.
 *
 * Source of truth is `public/blocks/{slug}/*.css|*.js`. BuildAssembler
 * projects those files there from each block's Blockstudio `_dist/` at
 * publish time, so the bytes inlined on-device are the same bytes the WP
 * editor and public site used — single compile, three consumers.
 */
final class BlockAssetCollector
{
    /** @var array<string, bool> */
    private array $seen = [];

    /** @var list<string> CSS files to inline (absolute paths under public/blocks). */
    private array $css = [];

    /** @var list<string> JS files to inline. */
    private array $js = [];

    /**
     * @param array<int, array<string, mixed>>|null $blockTree The screen's
     *        top-level block_tree, with optional nested children[].
     */
    public function __construct(?array $blockTree)
    {
        if (is_array($blockTree)) {
            $this->walk($blockTree);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function walk(array $blocks): void
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            if ($type !== '') {
                $this->collectFor($type);
            }

            $children = $block['children'] ?? $block['innerBlocks'] ?? null;
            if (is_array($children)) {
                $this->walk($children);
            }
        }
    }

    private function collectFor(string $type): void
    {
        $slug = str_contains($type, '/') ? explode('/', $type, 2)[1] : $type;
        if ($slug === '' || isset($this->seen[$slug])) {
            return;
        }
        // Only allow safe slug chars — no traversal.
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            return;
        }
        $this->seen[$slug] = true;

        $dir = public_path('blocks/' . $slug);
        if (! is_dir($dir)) {
            return;
        }
        foreach (['styles.inline.css', 'styles.scoped.css', 'styles.css'] as $cssName) {
            $path = $dir . DIRECTORY_SEPARATOR . $cssName;
            if (is_file($path)) {
                $this->css[] = $path;
            }
        }
        foreach (['scripts.inline.js', 'scripts.js', 'scripts.view.js'] as $jsName) {
            $path = $dir . DIRECTORY_SEPARATOR . $jsName;
            if (is_file($path)) {
                $this->js[] = $path;
            }
        }
    }

    public function inlineCss(): string
    {
        return $this->readAll($this->css);
    }

    public function inlineJs(): string
    {
        return $this->readAll($this->js);
    }

    /** @param list<string> $paths */
    private function readAll(array $paths): string
    {
        $out = '';
        foreach ($paths as $path) {
            $raw = @file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $out .= "/* " . basename(dirname($path)) . '/' . basename($path) . " */\n" . $raw . "\n";
        }
        return $out;
    }

    /** @return list<string> slug list (for debugging / admin UI). */
    public function slugs(): array
    {
        return array_keys($this->seen);
    }
}
