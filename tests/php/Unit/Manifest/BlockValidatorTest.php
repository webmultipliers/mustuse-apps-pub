<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Manifest;

use MustUse\Pub\Manifest\BlockValidator;
use PHPUnit\Framework\TestCase;

final class BlockValidatorTest extends TestCase
{
    private BlockValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new BlockValidator();
    }

    public function test_empty_tree_is_valid(): void
    {
        self::assertSame([], $this->validator->validate([]));
    }

    public function test_state_native_action_is_valid(): void
    {
        $errors = $this->validator->validate([[
            'type' => 'mustuse-apps-pub/native-action',
            'attributes' => [
                'capability' => 'camera',
                'callbackType' => 'state',
                'callbackSlot' => 'photo.capture',
            ],
        ]]);

        self::assertSame([], $errors);
    }

    public function test_endpoint_native_action_requires_relative_url(): void
    {
        $errors = $this->validator->validate([[
            'type' => 'mustuse-apps-pub/native-action',
            'attributes' => [
                'capability' => 'camera',
                'callbackType' => 'endpoint',
                'callbackUrl' => 'https://evil.example.com/capture',
            ],
        ]]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('external', $errors[0]);
    }

    public function test_missing_callback_type_is_reported(): void
    {
        $errors = $this->validator->validate([[
            'type' => 'mustuse-apps-pub/native-action',
            'attributes' => ['capability' => 'camera'],
        ]]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('callbackType', $errors[0]);
    }

    public function test_rejects_non_block_classified_capability(): void
    {
        $errors = $this->validator->validate([[
            'type' => 'mustuse-apps-pub/native-action',
            'attributes' => [
                'capability' => 'secure_storage',
                'callbackType' => 'state',
                'callbackSlot' => 'x',
            ],
        ]]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('secure_storage', $errors[0]);
        self::assertStringContainsString('shell', $errors[0]);
    }

    public function test_rejects_unknown_capability(): void
    {
        $errors = $this->validator->validate([[
            'type' => 'mustuse-apps-pub/native-action',
            'attributes' => [
                'capability' => 'telepathy',
                'callbackType' => 'state',
                'callbackSlot' => 'x',
            ],
        ]]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('unknown capability', $errors[0]);
    }

    public function test_state_callback_requires_slot(): void
    {
        $errors = $this->validator->validate([[
            'type' => 'mustuse-apps-pub/native-action',
            'attributes' => [
                'capability' => 'camera',
                'callbackType' => 'state',
            ],
        ]]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('callbackSlot', $errors[0]);
    }

    public function test_invalid_callback_type_is_reported(): void
    {
        $errors = $this->validator->validate([[
            'type' => 'mustuse-apps-pub/native-action',
            'attributes' => [
                'capability' => 'camera',
                'callbackType' => 'webhook',
            ],
        ]]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('invalid callbackType', $errors[0]);
    }

    public function test_walks_nested_inner_blocks(): void
    {
        $errors = $this->validator->validate([[
            'blockName' => 'core/group',
            'attrs' => [],
            'innerBlocks' => [[
                'blockName' => 'mustuse-apps-pub/native-action',
                'attrs' => [
                    'capability' => 'camera',
                    'callbackType' => 'endpoint',
                    'callbackUrl' => 'https://external.example.com/x',
                ],
            ]],
        ]]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('external', $errors[0]);
    }

    /** @return iterable<string, array{0:string}> */
    public static function newlyBlockClassifiedCapabilityProvider(): iterable
    {
        yield 'haptics'       => ['haptics'];
        yield 'flashlight'    => ['flashlight'];
        yield 'geolocation'   => ['geolocation'];
        yield 'microphone'    => ['microphone'];
        yield 'photo_library' => ['photo_library'];
        yield 'video_record'  => ['video_record'];
        yield 'dialog_alert'  => ['dialog_alert'];
        yield 'dialog_toast'  => ['dialog_toast'];
    }

    /** @dataProvider newlyBlockClassifiedCapabilityProvider */
    public function test_accepts_newly_block_classified_capabilities(string $capability): void
    {
        $errors = $this->validator->validate([[
            'type' => 'mustuse-apps-pub/native-action',
            'attributes' => [
                'capability'   => $capability,
                'callbackType' => 'state',
                'callbackSlot' => 'slot',
            ],
        ]]);

        self::assertSame([], $errors, \sprintf('Capability "%s" should validate on native-action.', $capability));
    }

    public function test_capability_action_type_on_generic_block(): void
    {
        $errors = $this->validator->validate([[
            'type' => 'core/button',
            'attributes' => [
                'action' => [
                    'type' => 'capability',
                    'capability' => 'camera',
                    'callbackType' => 'state',
                    'callbackSlot' => 'slot',
                ],
            ],
        ]]);

        self::assertSame([], $errors);
    }

    /** @return iterable<string, array{0:string}> */
    public static function externalCallbackUrlProvider(): iterable
    {
        yield 'absolute https'        => ['https://evil.example.com/capture'];
        yield 'absolute http'         => ['http://evil.example.com/capture'];
        yield 'protocol-relative'     => ['//evil.example.com/x'];
        yield 'javascript scheme'     => ['javascript:alert(1)'];
        yield 'file scheme'           => ['file:///etc/passwd'];
        yield 'data uri'              => ['data:text/html;base64,PHNjcmlwdD4='];
        yield 'mailto scheme'         => ['mailto:attacker@example.com'];
        yield 'ftp scheme'            => ['ftp://evil.example.com/a'];
        yield 'custom scheme'         => ['x-evil+scheme:whatever'];
        yield 'uppercase https'       => ['HTTPS://EVIL.EXAMPLE.COM/x'];
    }

    /** @dataProvider externalCallbackUrlProvider */
    public function test_rejects_external_callback_urls(string $url): void
    {
        $errors = $this->validator->validate([[
            'type' => 'mustuse-apps-pub/native-action',
            'attributes' => [
                'capability' => 'camera',
                'callbackType' => 'endpoint',
                'callbackUrl' => $url,
            ],
        ]]);

        self::assertCount(1, $errors, 'Exactly one error expected for external URL.');
        self::assertStringContainsString('external', $errors[0]);
        self::assertStringContainsString($url, $errors[0]);
    }

    /** @return iterable<string, array{0:string}> */
    public static function relativeCallbackUrlProvider(): iterable
    {
        yield 'root-absolute path'    => ['/apps/foo/scan-results'];
        yield 'bare path'             => ['apps/foo/scan-results'];
        yield 'query-only'            => ['?callback=123'];
        yield 'nested path'           => ['/wp-json/mua/v1/cb'];
        yield 'with fragment'         => ['/apps/foo#anchor'];
    }

    /** @dataProvider relativeCallbackUrlProvider */
    public function test_accepts_pub_relative_callback_urls(string $url): void
    {
        $errors = $this->validator->validate([[
            'type' => 'mustuse-apps-pub/native-action',
            'attributes' => [
                'capability' => 'camera',
                'callbackType' => 'endpoint',
                'callbackUrl' => $url,
            ],
        ]]);

        self::assertSame([], $errors, \sprintf('Pub-relative URL "%s" should validate.', $url));
    }
}
