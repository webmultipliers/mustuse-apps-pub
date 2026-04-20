<?php

declare(strict_types=1);

/**
 * PHP-CS-Fixer config — narrow scope, used only to remove the two recurring
 * style hints IDEs have been flagging (PHP6616 + PHP7101). If this grows
 * into a general coding-standards enforcer, move it into a broader ruleset
 * with a team discussion first.
 */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests/php', __DIR__ . '/tests/stubs'])
    ->notPath('tests/php/Integration/wp-tests-config.php')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // PHP6616 — prefix internal-function calls with `\` so PHP can skip
        // the userland-shadow lookup. Micro-optimization, but the diagnostic
        // noise from NOT doing it was the larger problem.
        'native_function_invocation' => [
            'include' => ['@internal'],
            'scope'   => 'namespaced',
            'strict'  => true,
        ],
        // PHP7101 — remove unused `use` statements that slip in after refactors.
        'no_unused_imports' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.phpcs.cache');
