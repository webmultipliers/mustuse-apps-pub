<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Admin\AppPermissions;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Data\Models\Screen;
use PHPUnit\Framework\TestCase;

final class AppPermissionsTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $postMeta = [];
    /** @var array<int, string> */
    private array $postTypes = [];
    /** @var array<int, bool> */
    private array $isAdmin = [];

    protected function setUp(): void
    {
        Monkey\setUp();

        Functions\when('get_post_meta')->alias(
            function (int $id, string $key) {
                return $this->postMeta[$id][$key] ?? '';
            }
        );
        Functions\when('get_post_type')->alias(
            fn (int $id): string => $this->postTypes[$id] ?? ''
        );
        Functions\when('user_can')->alias(
            fn (int $userId, string $cap): bool => $cap === 'manage_options' && ($this->isAdmin[$userId] ?? false)
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_unrelated_caps_pass_through(): void
    {
        $caps = AppPermissions::mapMetaCap(['edit_pages'], 'edit_pages', 1, [42]);
        self::assertSame(['edit_pages'], $caps);
    }

    public function test_admin_always_passes_through(): void
    {
        $this->postTypes[42]                 = App::POST_TYPE;
        $this->postMeta[42]['_mua_app_owners'] = [99]; // someone else owns it
        $this->isAdmin[1]                    = true;

        $caps = AppPermissions::mapMetaCap(['edit_pages'], 'edit_post', 1, [42]);
        self::assertSame(['edit_pages'], $caps);
    }

    public function test_no_owners_configured_falls_through(): void
    {
        $this->postTypes[42] = App::POST_TYPE;

        $caps = AppPermissions::mapMetaCap(['edit_pages'], 'edit_post', 1, [42]);
        self::assertSame(['edit_pages'], $caps);
    }

    public function test_non_owner_non_admin_denied_when_owners_configured(): void
    {
        $this->postTypes[42]                 = App::POST_TYPE;
        $this->postMeta[42]['_mua_app_owners'] = [99];

        $caps = AppPermissions::mapMetaCap(['edit_pages'], 'edit_post', 1, [42]);
        self::assertSame(['do_not_allow'], $caps);
    }

    public function test_owner_passes(): void
    {
        $this->postTypes[42]                 = App::POST_TYPE;
        $this->postMeta[42]['_mua_app_owners'] = [99, 100];

        $caps = AppPermissions::mapMetaCap(['edit_pages'], 'edit_post', 99, [42]);
        self::assertSame(['edit_pages'], $caps);
    }

    public function test_screen_post_resolves_owner_via_app_id(): void
    {
        $this->postTypes[200]                  = Screen::POST_TYPE;
        $this->postMeta[200]['_mua_app_id']     = 42;
        $this->postTypes[42]                   = App::POST_TYPE;
        $this->postMeta[42]['_mua_app_owners']  = [99];

        $denied = AppPermissions::mapMetaCap(['edit_pages'], 'edit_post', 1, [200]);
        self::assertSame(['do_not_allow'], $denied);

        $allowed = AppPermissions::mapMetaCap(['edit_pages'], 'edit_post', 99, [200]);
        self::assertSame(['edit_pages'], $allowed);
    }

    public function test_ship_cap_uses_first_arg_as_app_id(): void
    {
        $this->postMeta[42]['_mua_app_owners'] = [99];

        $denied = AppPermissions::mapMetaCap(['edit_pages'], 'mua_ship_app', 1, [42]);
        self::assertSame(['do_not_allow'], $denied);

        $allowed = AppPermissions::mapMetaCap(['edit_pages'], 'mua_ship_app', 99, [42]);
        self::assertSame(['edit_pages'], $allowed);
    }

    public function test_owners_of_normalises_to_int_list(): void
    {
        $this->postMeta[42]['_mua_app_owners'] = ['99', 100, 100, '0', 'abc'];

        self::assertSame([99, 100], AppPermissions::ownersOf(42));
    }
}
