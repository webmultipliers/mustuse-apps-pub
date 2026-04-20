<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Manifest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Manifest\ManifestValidator;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * End-to-end ManifestValidator exercise — feeds full manifest fixtures at
 * the validator to assert it agrees with the pub-shell manifest contract.
 * Every rejection path is a reason the shell would refuse the manifest at
 * runtime, so we want to freeze the exact error messages here.
 */
final class ManifestValidatorTest extends TestCase
{
    private ManifestValidator $validator;

    protected function setUp(): void
    {
        Monkey\setUp();
        $this->validator = new ManifestValidator();

        // App::appType() reads _mua_app_type post meta; default to mobile_ios.
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key) => $key === '_mua_app_type' ? 'mobile_ios' : ''
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    private function makeApp(int $id = 1, string $appType = 'mobile_ios'): App
    {
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key) => $key === '_mua_app_type' ? $appType : ''
        );
        return new App(WP_Post::make(['ID' => $id, 'post_name' => 'my-app', 'post_title' => 'My App']));
    }

    /** @return array<string, mixed> */
    private function validManifest(): array
    {
        return [
            'version'   => 1,
            'app'       => [
                'id'       => 1,
                'slug'     => 'my-app',
                'name'     => 'My App',
                'app_type' => 'mobile_ios',
            ],
            'branding'  => ['primary_color' => '#000'],
            'endpoints' => ['content' => 'https://pub.test/wp-json/mustuse-apps-pub/v1/apps/my-app/content'],
            'screens'   => [
                [
                    'id'         => 'home',
                    'type'       => 'screen',
                    'title'      => 'Home',
                    'block_tree' => [],
                ],
            ],
        ];
    }

    public function test_valid_manifest_produces_no_errors(): void
    {
        self::assertSame([], $this->validator->validate($this->validManifest(), $this->makeApp()));
    }

    public function test_missing_top_level_fields_are_reported(): void
    {
        $errors = $this->validator->validate(
            ['screens' => []],
            $this->makeApp()
        );
        self::assertContains('Missing required manifest field: version', $errors);
        self::assertContains('Missing required manifest field: app', $errors);
        self::assertContains('Missing required manifest field: branding', $errors);
        self::assertContains('Missing required manifest field: endpoints', $errors);
    }

    public function test_missing_app_fields_are_reported(): void
    {
        $manifest = $this->validManifest();
        $manifest['app'] = ['name' => 'X'];
        $errors = $this->validator->validate($manifest, $this->makeApp());

        self::assertContains('Missing required app field: id', $errors);
        self::assertContains('Missing required app field: slug', $errors);
        self::assertContains('Missing required app field: app_type', $errors);
    }

    public function test_desktop_app_type_is_rejected_in_v1(): void
    {
        $manifest = $this->validManifest();
        $manifest['app']['app_type'] = 'desktop_macos';

        $errors = $this->validator->validate($manifest, $this->makeApp(1, 'desktop_macos'));
        self::assertNotEmpty($errors);
        self::assertStringContainsString('not yet supported in v1', $errors[0]);
    }

    public function test_unknown_app_type_is_rejected(): void
    {
        $manifest = $this->validManifest();
        $manifest['app']['app_type'] = 'smart_toaster';

        $errors = $this->validator->validate($manifest, $this->makeApp());
        self::assertNotEmpty($errors);
        self::assertStringContainsString('Unknown app_type "smart_toaster"', $errors[0]);
    }

    public function test_desktop_reserved_fields_are_rejected_for_mobile(): void
    {
        $manifest = $this->validManifest();
        $manifest['menu'] = ['File', 'Edit'];
        $manifest['window'] = ['width' => 800];
        $manifest['shell_mode'] = 'standalone';
        $manifest['keyboard_shortcuts'] = [];

        $errors = $this->validator->validate($manifest, $this->makeApp(1, 'mobile_ios'));

        foreach (['menu', 'window', 'shell_mode', 'keyboard_shortcuts'] as $field) {
            self::assertNotEmpty(\array_filter(
                $errors,
                static fn (string $msg) => \str_contains($msg, '"' . $field . '"')
            ), "Expected rejection of desktop-reserved field: {$field}");
        }
    }

    /** @return iterable<string, array{0:string}> */
    public static function invalidDeeplinkSchemeProvider(): iterable
    {
        yield 'uppercase'      => ['MYAPP'];
        yield 'starts-with-digit' => ['1myapp'];
        yield 'has-hyphen'     => ['my-app'];
        yield 'has-space'      => ['my app'];
        yield 'empty'          => ['']; // regex rejects empty
    }

    /** @dataProvider invalidDeeplinkSchemeProvider */
    public function test_invalid_deeplink_scheme_is_rejected(string $scheme): void
    {
        $manifest = $this->validManifest();
        $manifest['deeplink'] = ['scheme' => $scheme];
        $errors = $this->validator->validate($manifest, $this->makeApp());

        self::assertNotEmpty(\array_filter(
            $errors,
            static fn (string $msg) => \str_contains($msg, 'Deep link scheme')
        ), "Expected rejection of scheme: {$scheme}");
    }

    public function test_valid_deeplink_shape_accepted(): void
    {
        $manifest = $this->validManifest();
        $manifest['deeplink'] = ['scheme' => 'myapp', 'host' => 'app.example.com'];

        self::assertSame([], $this->validator->validate($manifest, $this->makeApp()));
    }

    public function test_invalid_deeplink_host_is_rejected(): void
    {
        $manifest = $this->validManifest();
        $manifest['deeplink'] = ['host' => 'not a host!'];

        $errors = $this->validator->validate($manifest, $this->makeApp());
        self::assertNotEmpty(\array_filter(
            $errors,
            static fn (string $msg) => \str_contains($msg, 'Deep link host')
        ));
    }

    public function test_non_array_deeplink_is_rejected(): void
    {
        $manifest = $this->validManifest();
        $manifest['deeplink'] = 'myapp://';

        $errors = $this->validator->validate($manifest, $this->makeApp());
        self::assertContains('Manifest "deeplink" field must be an object.', $errors);
    }

    public function test_bottom_nav_over_five_items_is_rejected(): void
    {
        $manifest = $this->validManifest();
        $manifest['navigation'] = [
            'bottom_nav' => \array_fill(0, 6, ['label' => 'x']),
            'drawer'     => [],
        ];

        $errors = $this->validator->validate($manifest, $this->makeApp());
        self::assertNotEmpty(\array_filter(
            $errors,
            static fn (string $msg) => \str_contains($msg, 'Bottom navigation has 6 items')
        ));
    }

    public function test_bottom_nav_at_cap_is_accepted(): void
    {
        $manifest = $this->validManifest();
        $manifest['navigation'] = [
            'bottom_nav' => \array_fill(0, 5, ['label' => 'x']),
            'drawer'     => [],
        ];

        $errors = $this->validator->validate($manifest, $this->makeApp());
        self::assertEmpty(\array_filter(
            $errors,
            static fn (string $msg) => \str_contains($msg, 'Bottom navigation')
        ));
    }

    public function test_side_nav_must_be_an_array_when_present(): void
    {
        $manifest = $this->validManifest();
        $manifest['navigation'] = [
            'bottom_nav' => [],
            'side_nav'   => 'not-an-array',
            'drawer'     => [],
        ];

        $errors = $this->validator->validate($manifest, $this->makeApp());
        self::assertContains('Side navigation must be an array.', $errors);
    }

    public function test_version_must_be_a_positive_integer(): void
    {
        $manifest            = $this->validManifest();
        $manifest['version'] = 0;

        $errors = $this->validator->validate($manifest, $this->makeApp());
        self::assertContains('Manifest "version" must be a positive integer.', $errors);
    }

    public function test_version_above_known_max_is_rejected(): void
    {
        $manifest            = $this->validManifest();
        $manifest['version'] = \MustUse\Pub\Manifest\ManifestBuilder::SCHEMA_VERSION + 1;

        $errors = $this->validator->validate($manifest, $this->makeApp());
        self::assertNotEmpty(\array_filter(
            $errors,
            static fn (string $msg) => \str_contains($msg, 'newer than this publisher knows about')
        ));
    }

    public function test_min_shell_version_higher_than_version_is_rejected(): void
    {
        $manifest                       = $this->validManifest();
        $manifest['version']            = 1;
        $manifest['min_shell_version']  = 2;

        $errors = $this->validator->validate($manifest, $this->makeApp());
        self::assertNotEmpty(\array_filter(
            $errors,
            static fn (string $msg) => \str_contains($msg, 'cannot be higher than "version"')
        ));
    }

    public function test_side_nav_with_many_items_is_accepted(): void
    {
        $manifest = $this->validManifest();
        $manifest['navigation'] = [
            'bottom_nav' => [],
            'side_nav'   => \array_fill(0, 20, ['screen_id' => 'x', 'path' => '/x']),
            'drawer'     => [],
        ];

        $errors = $this->validator->validate($manifest, $this->makeApp());
        self::assertEmpty(\array_filter(
            $errors,
            static fn (string $msg) => \str_contains($msg, 'Side navigation')
        ));
    }

    public function test_screen_missing_type_is_rejected(): void
    {
        $manifest = $this->validManifest();
        $manifest['screens'] = [['id' => 'x', 'block_tree' => []]];

        $errors = $this->validator->validate($manifest, $this->makeApp());
        self::assertNotEmpty(\array_filter(
            $errors,
            static fn (string $msg) => \str_contains($msg, 'missing a valid "type" field')
        ));
    }

    public function test_invalid_screen_type_is_rejected(): void
    {
        $manifest = $this->validManifest();
        $manifest['screens'] = [['id' => 'x', 'type' => 'popup', 'block_tree' => []]];

        $errors = $this->validator->validate($manifest, $this->makeApp());
        self::assertNotEmpty(\array_filter(
            $errors,
            static fn (string $msg) => \str_contains($msg, '"type" field')
        ));
    }

    public function test_block_tree_errors_are_prefixed_with_screen_id(): void
    {
        $manifest = $this->validManifest();
        $manifest['screens'] = [[
            'id'         => 'camera-screen',
            'type'       => 'screen',
            'title'      => 'Camera',
            'block_tree' => [[
                'type' => 'mustuse-apps-pub/native-action',
                'attributes' => [
                    'capability' => 'camera',
                    'callbackType' => 'endpoint',
                    'callbackUrl' => 'https://evil.example.com/x',
                ],
            ]],
        ]];

        $errors = $this->validator->validate($manifest, $this->makeApp());
        self::assertNotEmpty($errors);
        self::assertStringContainsString('[Screen: camera-screen]', $errors[0]);
        self::assertStringContainsString('external', $errors[0]);
    }
}
