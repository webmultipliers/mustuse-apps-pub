<?php

declare(strict_types=1);

namespace MustUse\Pub\Blocks;

use MustUse\Pub\Logging\ShellAccessLog;

/**
 * Static analyser that catches attribute-name drift between a block's
 * `block.json` schema and its `mobile.blade.php` renderer. Drift is how we
 * shipped unrendered hero/article-card to production last round (block.json
 * said `heading`, Blade read `title`) — this stops the next one.
 *
 * Runs as part of the build pipeline (BuildAssembler::assembleBase) and
 * writes any findings via ShellAccessLog (which honours WP_DEBUG_LOG and
 * applies redaction). A `MUA_STRICT_BLOCK_AUDIT` env can be set in
 * non-production envs to throw instead.
 *
 * Covered today:
 *  - Every `$block['foo']` reference in mobile.blade.php must either be
 *    declared in block.json attributes, be an auto-injected key produced by
 *    BlockAttributeNormalizer (article, items, author, cta, ...), or be a
 *    shell-provided helper (children, gates).
 *  - Attribute ids themselves must be lowerCamelCase so we don't mix naming
 *    conventions across blocks.
 */
final class BlockSchemaAuditor
{
    /** Keys that the shell or the normalizer inject on top of block.json. */
    private const SHELL_INJECTED = [
        'children', 'gates',
    ];

    private const NORMALIZER_INJECTED = [
        'article', 'items', 'author', 'cta',
    ];

    /**
     * @param array<string, string> $blockSources slug => absolute dir
     * @return list<array{block: string, issue: string, detail: string}>
     */
    public static function audit(array $blockSources): array
    {
        $findings = [];
        foreach ($blockSources as $slug => $dir) {
            $blockJson = $dir . '/block.json';
            $blade     = $dir . '/mobile.blade.php';
            if (! \is_file($blockJson) || ! \is_file($blade)) {
                continue;
            }

            $declared = self::declaredAttributeIds($blockJson);
            $used     = self::referencedBladeKeys($blade);

            foreach ($declared as $id) {
                if ($id !== '' && ! \preg_match('/^[a-z][a-zA-Z0-9]*$/', $id)) {
                    $findings[] = [ 'block' => $slug, 'issue' => 'bad_attribute_id', 'detail' => $id ];
                }
            }

            $known = \array_merge(
                $declared,
                self::SHELL_INJECTED,
                self::NORMALIZER_INJECTED,
                (array) apply_filters('mua_block_audit_known_keys', [], $slug)
            );
            foreach ($used as $key) {
                if (! \in_array($key, $known, TRUE)) {
                    $findings[] = [
                        'block'  => $slug,
                        'issue'  => 'unknown_blade_key',
                        'detail' => "\$block['{$key}'] is not declared in block.json attributes or normalized by BlockAttributeNormalizer",
                    ];
                }
            }
        }

        foreach ($findings as $f) {
            ShellAccessLog::record('block_schema_audit', $f);
        }
        if ($findings !== [] && \getenv('MUA_STRICT_BLOCK_AUDIT')) {
            throw new \RuntimeException('Block schema audit failed: ' . \json_encode($findings));
        }
        return $findings;
    }

    /**
     * @return list<string>
     */
    private static function declaredAttributeIds(string $blockJsonPath): array
    {
        $raw  = (string) \file_get_contents($blockJsonPath);
        $json = \json_decode($raw, TRUE);
        $attrs = $json['blockstudio']['attributes'] ?? [];
        if (! \is_array($attrs)) {
            return [];
        }
        return \array_values(\array_filter(\array_map(
            static fn ( $a ) => \is_array( $a ) && isset( $a['id'] ) ? (string) $a['id'] : '',
            $attrs
        )));
    }

    /**
     * @return list<string>
     */
    private static function referencedBladeKeys(string $bladePath): array
    {
        $body = (string) \file_get_contents($bladePath);
        // Match $block['foo'] — single or double-quoted.
        \preg_match_all("/\\\$block\\[\\s*['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\\s*\\]/", $body, $m);
        return \array_values(\array_unique($m[1]));
    }
}
