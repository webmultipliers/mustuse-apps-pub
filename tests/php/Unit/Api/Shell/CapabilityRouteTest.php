<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Api\Shell;

use MustUse\Pub\Api\Shell\CapabilityRoute;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Guards the `validateAdvertisement` shape check — shells persist whatever
 * passes, so every reject path is part of the plugin's admin-surface
 * defence perimeter. Covered via reflection because the route's public
 * `handle()` also needs WP_REST_Request/WP_REST_Response and REST routing.
 */
final class CapabilityRouteTest extends TestCase
{
    private static function validate(array $body): bool
    {
        $r = new ReflectionMethod(CapabilityRoute::class, 'validateAdvertisement');
        $r->setAccessible(true);
        return (bool) $r->invoke(null, $body);
    }

    private function validBody(array $overrides = []): array
    {
        return \array_merge([
            'app_type'            => 'mobile_ios',
            'shell_version'       => '1.2.3',
            'native_capabilities' => ['camera' => true, 'biometrics' => true],
            'component_registry'  => ['core/paragraph', 'mustuse-apps-pub/hero'],
        ], $overrides);
    }

    public function test_accepts_well_formed_advertisement(): void
    {
        self::assertTrue(self::validate($this->validBody()));
    }

    public function test_missing_app_type_is_rejected(): void
    {
        $body = $this->validBody();
        unset($body['app_type']);
        self::assertFalse(self::validate($body));
    }

    /** @return iterable<string, array{0:mixed}> */
    public static function invalidAppTypeProvider(): iterable
    {
        yield 'uppercase' => ['MOBILE_IOS'];
        yield 'too short' => ['io'];
        yield 'too long'  => [\str_repeat('a', 33)];
        yield 'digits'    => ['mobile1'];
        yield 'not-string' => [123];
    }

    /** @dataProvider invalidAppTypeProvider */
    public function test_invalid_app_type_is_rejected(mixed $appType): void
    {
        self::assertFalse(self::validate($this->validBody(['app_type' => $appType])));
    }

    /** @return iterable<string, array{0:mixed}> */
    public static function invalidShellVersionProvider(): iterable
    {
        yield 'letters'   => ['v1.0.0'];
        yield 'too many'  => ['1.2.3.4.5'];
        yield 'empty'     => [''];
        yield 'not-string' => [1.0];
    }

    /** @dataProvider invalidShellVersionProvider */
    public function test_invalid_shell_version_is_rejected(mixed $version): void
    {
        self::assertFalse(self::validate($this->validBody(['shell_version' => $version])));
    }

    public function test_unknown_capability_key_is_rejected(): void
    {
        self::assertFalse(self::validate($this->validBody([
            'native_capabilities' => ['telepathy' => true],
        ])));
    }

    public function test_non_scalar_capability_value_is_rejected(): void
    {
        self::assertFalse(self::validate($this->validBody([
            'native_capabilities' => ['camera' => ['nested']],
        ])));
    }

    public function test_every_v1_capability_is_accepted(): void
    {
        $all = \array_fill_keys(
            \array_keys(\MustUse\Pub\Manifest\CapabilityRegistry::MOBILE_CAPABILITIES),
            true
        );
        self::assertTrue(self::validate($this->validBody(['native_capabilities' => $all])));
    }

    public function test_too_many_capabilities_are_rejected(): void
    {
        $oversized = [];
        for ($i = 0; $i < 65; $i++) {
            $oversized['camera_' . $i] = true; // bogus keys but hits the size cap first
        }
        self::assertFalse(self::validate($this->validBody(['native_capabilities' => $oversized])));
    }

    /** @return iterable<string, array{0:string[]}> */
    public static function invalidComponentRegistryProvider(): iterable
    {
        yield 'no namespace'     => [['paragraph']];
        yield 'html tag'         => [['<script>alert(1)</script>']];
        yield 'uppercase'        => [['CORE/paragraph']];
        yield 'spaces'           => [['core/ paragraph']];
        yield 'double slash'     => [['core//paragraph']];
        yield 'two namespaces'   => [['a/b/c']];
        yield 'starts with dash' => [['-foo/bar']];
    }

    /** @dataProvider invalidComponentRegistryProvider */
    public function test_hostile_component_entries_are_rejected(array $registry): void
    {
        self::assertFalse(self::validate($this->validBody(['component_registry' => $registry])));
    }

    public function test_well_formed_component_entries_are_accepted(): void
    {
        self::assertTrue(self::validate($this->validBody([
            'component_registry' => [
                'core/paragraph',
                'core/heading',
                'mustuse-apps-pub/native-action',
                'mustuse-apps-pub-campaign/rich-content',
            ],
        ])));
    }

    public function test_non_array_capabilities_is_rejected(): void
    {
        self::assertFalse(self::validate($this->validBody(['native_capabilities' => 'yes'])));
    }

    public function test_non_array_component_registry_is_rejected(): void
    {
        self::assertFalse(self::validate($this->validBody(['component_registry' => 'yes'])));
    }
}
