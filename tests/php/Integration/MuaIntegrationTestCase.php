<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Integration;

use WP_UnitTestCase;

/**
 * Base test case for integration tests.
 *
 * Why this exists: `WP_UnitTestCase::expectDeprecated()` (wp-phpunit 6.9.x)
 * still calls `\PHPUnit\Util\Test::parseTestMethodAnnotations()` and
 * `$this->getName(false)` — both removed in PHPUnit 10. That makes every
 * test fatal before it reaches its own body, regardless of what it does.
 *
 * This base overrides `expectDeprecated()` with a PHPUnit-10-safe
 * re-implementation that reads the same `@expectedDeprecated` /
 * `@expectedIncorrectUsage` doc-comment annotations via reflection rather
 * than the removed PHPUnit helper. Behavior is intentionally identical —
 * WP core tests that rely on these annotations will still observe them.
 *
 * Remove this shim once wp-phpunit ships a PHPUnit-10-compatible abstract
 * testcase (see todo.md → "WordPress integration testsuite").
 */
abstract class MuaIntegrationTestCase extends WP_UnitTestCase
{
    /** @return array{class:array<string, string[]>, method:array<string, string[]>} */
    private function readMuaAnnotations(): array
    {
        $result = [
            'class'  => [],
            'method' => [],
        ];

        foreach (['class' => static::class, 'method' => null] as $depth => $_) {
            try {
                if ($depth === 'class') {
                    $comment = (new \ReflectionClass(static::class))->getDocComment();
                } else {
                    $comment = (new \ReflectionMethod(static::class, $this->name()))->getDocComment();
                }
            } catch (\ReflectionException) {
                $comment = false;
            }

            if (! \is_string($comment)) {
                continue;
            }

            foreach (['expectedDeprecated', 'expectedIncorrectUsage'] as $tag) {
                if (\preg_match_all('/@' . \preg_quote($tag, '/') . '\s+(.+)/i', $comment, $matches)) {
                    $result[$depth][$tag] = \array_map('trim', $matches[1]);
                }
            }
        }

        return $result;
    }

    public function expectDeprecated(): void
    {
        $annotations = $this->readMuaAnnotations();

        foreach (['class', 'method'] as $depth) {
            if (! empty($annotations[$depth]['expectedDeprecated'])) {
                $this->expected_deprecated = \array_merge(
                    $this->expected_deprecated,
                    $annotations[$depth]['expectedDeprecated']
                );
            }
            if (! empty($annotations[$depth]['expectedIncorrectUsage'])) {
                $this->expected_doing_it_wrong = \array_merge(
                    $this->expected_doing_it_wrong,
                    $annotations[$depth]['expectedIncorrectUsage']
                );
            }
        }

        add_action('deprecated_function_run',     [$this, 'deprecated_function_run'], 10, 3);
        add_action('deprecated_argument_run',     [$this, 'deprecated_function_run'], 10, 3);
        add_action('deprecated_class_run',        [$this, 'deprecated_function_run'], 10, 3);
        add_action('deprecated_file_included',    [$this, 'deprecated_function_run'], 10, 4);
        add_action('deprecated_hook_run',         [$this, 'deprecated_function_run'], 10, 4);
        add_action('doing_it_wrong_run',          [$this, 'doing_it_wrong_run'], 10, 3);

        add_action('deprecated_function_trigger_error', '__return_false');
        add_action('deprecated_argument_trigger_error', '__return_false');
        add_action('deprecated_class_trigger_error',    '__return_false');
        add_action('deprecated_file_trigger_error',     '__return_false');
        add_action('deprecated_hook_trigger_error',     '__return_false');
        add_action('doing_it_wrong_trigger_error',      '__return_false');
    }
}
