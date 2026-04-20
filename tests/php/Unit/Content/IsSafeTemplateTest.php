<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Content;

use MustUse\Pub\Content\DeeplinkResolver;
use MustUse\Pub\Content\RouteMapper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The `isSafeTemplate` guard sits in two places (DeeplinkResolver +
 * RouteMapper). Both copies must behave identically — any drift would let
 * a hostile template reach one resolver but not the other. The tests run
 * the same accept/reject matrix against both classes, then explicitly
 * assert divergence-free behaviour.
 */
final class IsSafeTemplateTest extends TestCase
{
    private static function invoke(string $fqcn, string $template): bool
    {
        // DeeplinkResolver::isSafeTemplate is public (admin meta-box uses it
        // for validation); RouteMapper's stays private. Reflection works for
        // both — and keeps the two checks invokable through the same helper.
        $r = new ReflectionMethod($fqcn, 'isSafeTemplate');
        $r->setAccessible(true);
        return (bool) $r->invoke(null, $template);
    }

    /** @return iterable<string, array{0:string}> */
    public static function acceptedTemplateProvider(): iterable
    {
        yield 'root article id'      => ['/article/{id}'];
        yield 'page id'              => ['/page/{id}'];
        yield 'nested'               => ['/app/{app}/screen/{screen}'];
        yield 'trailing slash'       => ['/article/{id}/'];
        yield 'placeholders no slash' => ['/{slug}'];
        yield 'literal path'         => ['/about'];
        yield 'with dash'            => ['/help-center/{id}'];
        yield 'with underscore'      => ['/foo_bar/{id}'];
        yield 'with dot'             => ['/v1.0/{slug}'];
        yield 'length 200 exactly'   => ['/' . \str_repeat('a', 199)];
    }

    /** @dataProvider acceptedTemplateProvider */
    public function test_accepts_safe_templates(string $template): void
    {
        self::assertTrue(self::invoke(DeeplinkResolver::class, $template), 'DeeplinkResolver rejected: ' . $template);
        self::assertTrue(self::invoke(RouteMapper::class, $template), 'RouteMapper rejected: ' . $template);
    }

    /** @return iterable<string, array{0:string}> */
    public static function rejectedTemplateProvider(): iterable
    {
        yield 'empty'                 => [''];
        yield 'length > 200'           => ['/' . \str_repeat('a', 200)];
        yield 'anchor start'          => ['^/article/{id}'];
        yield 'anchor end'            => ['/article/{id}$'];
        yield 'alternation'           => ['/article|/post'];
        yield 'quantifier star'       => ['/article/*'];
        yield 'quantifier plus'       => ['/article/+'];
        yield 'quantifier question'   => ['/article/?'];
        yield 'char class'            => ['/article/[abc]'];
        yield 'escape sequence'       => ['/article/\d+'];
        yield 'parentheses'           => ['/(a|b)/{id}'];
        yield 'whitespace'            => ['/article/ id'];
        yield 'tab'                   => ["/article/\tid"];
        yield 'newline'               => ["/article/\nid"];
        yield 'backslash'             => ['/article\\{id}'];
        yield 'percent encoded'       => ['/article/%20{id}'];
        yield 'colon'                 => ['/article:{id}'];
        yield 'angle bracket'         => ['/article/<id>'];
        // Redundant / relative segments — ambiguous routing, so rejected.
        yield 'double slash mid'      => ['/article//{id}'];
        yield 'double slash leading'  => ['//article/{id}'];
        yield 'dot segment leading'   => ['/./article/{id}'];
        yield 'dot segment trailing'  => ['/article/{id}/.'];
        yield 'parent segment'        => ['/article/../{id}'];
        yield 'parent trailing'       => ['/article/{id}/..'];
    }

    /** @dataProvider rejectedTemplateProvider */
    public function test_rejects_hostile_templates(string $template): void
    {
        self::assertFalse(self::invoke(DeeplinkResolver::class, $template), 'DeeplinkResolver accepted: ' . $template);
        self::assertFalse(self::invoke(RouteMapper::class, $template), 'RouteMapper accepted: ' . $template);
    }

    public function test_both_resolvers_agree_on_every_case(): void
    {
        $all = \array_merge(
            \array_map(static fn ($c) => [true, $c[0]], \iterator_to_array(self::acceptedTemplateProvider(), false)),
            \array_map(static fn ($c) => [false, $c[0]], \iterator_to_array(self::rejectedTemplateProvider(), false))
        );

        foreach ($all as [$expected, $template]) {
            $dl = self::invoke(DeeplinkResolver::class, $template);
            $rm = self::invoke(RouteMapper::class, $template);
            self::assertSame(
                $dl,
                $rm,
                \sprintf('Resolver drift on "%s": DeeplinkResolver=%s, RouteMapper=%s', $template, \var_export($dl, true), \var_export($rm, true))
            );
            self::assertSame($expected, $dl, 'Unexpected classification for: ' . $template);
        }
    }
}
