<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Integration\Api;

use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Jobs\ProjectBuildToGithub;
use MustUse\Pub\Support\SecureStorage;
use MustUse\Pub\Tests\Integration\MuaIntegrationTestCase;
use WP_REST_Request;

/**
 * Freezes the Ship It → GitHub projection handoff:
 *
 *   - ShipRoute returns 202 with env_for_bifrost populated, branch named
 *   - _mua_last_build_status flips to 'pending' + stale compare_url / error
 *     are cleared before the job runs
 *   - ProjectBuildToGithub is enqueued on the Action Scheduler bus under
 *     the mustuse-apps-pub group
 *
 * The Action Scheduler job itself is exercised in
 * Unit/Jobs/ProjectBuildToGithubTest with a fake projector so this
 * integration test doesn't need to stub HTTP.
 */
final class ShipRouteTest extends MuaIntegrationTestCase
{
    private int $adminId = 0;

    public function set_up(): void
    {
        parent::set_up();

        $created = self::factory()->user->create(['role' => 'administrator']);
        if (is_wp_error($created)) {
            self::fail('Failed to create admin user: ' . $created->get_error_message());
        }
        $this->adminId = $created;
        wp_set_current_user($this->adminId);

        // Per-app build settings are set on each test's app instance, not globally.
    }

    public function tear_down(): void
    {
        // Remove any scheduled projection job left over from the test.
        if (\function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(ProjectBuildToGithub::ACTION, [], 'mustuse-apps-pub');
        }

        parent::tear_down();
    }

    public function test_ship_returns_202_with_env_for_bifrost_and_enqueues_projection_job(): void
    {
        $app = App::create('Integration Test App');
        self::assertInstanceOf(App::class, $app);
        wp_update_post(['ID' => $app->id(), 'post_status' => 'publish']);
        $app->updateMeta('deeplink_scheme', 'inttest');
        $app->updateMeta('deeplink_host', 'integration.example.com');
        $app->updateMeta('build_repo_url', 'https://github.com/owner/repo.git');
        $app->updateMeta('github_token', SecureStorage::encrypt('ghp_fake_pat_for_tests'));

        // Seed stale build state so we can assert ShipRoute clears it.
        update_post_meta($app->id(), '_mua_last_build_status', 'failed');
        update_post_meta($app->id(), '_mua_last_build_error', 'previous failure');
        update_post_meta($app->id(), '_mua_last_build_compare_url', 'https://github.com/old/old/compare/main...build-old');

        $request = new WP_REST_Request('POST', '/mustuse-apps-pub/v1/apps/' . $app->id() . '/ship');
        $response = rest_do_request($request);

        self::assertSame(202, $response->get_status(), 'Ship request should enqueue asynchronously.');

        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertSame('pending', $data['status']);
        self::assertStringStartsWith('build/integration-test-app-', $data['branch']);

        // env_for_bifrost contract: list of {name, value, secret}, including
        // at minimum MUA_APPKEY (secret) and NATIVEPHP_APP_ID (not secret).
        self::assertIsArray($data['env_for_bifrost']);
        $byName = [];
        foreach ($data['env_for_bifrost'] as $row) {
            self::assertArrayHasKey('name', $row);
            self::assertArrayHasKey('value', $row);
            self::assertArrayHasKey('secret', $row);
            $byName[$row['name']] = $row;
        }
        self::assertArrayHasKey('MUA_APPKEY', $byName);
        self::assertTrue($byName['MUA_APPKEY']['secret']);
        self::assertSame('inttest', $byName['NATIVEPHP_DEEPLINK_SCHEME']['value']);
        self::assertSame('integration.example.com', $byName['NATIVEPHP_DEEPLINK_HOST']['value']);
        self::assertStringStartsWith('com.mustuse.', $byName['NATIVEPHP_APP_ID']['value']);

        // Meta state: pending, stale fields cleared.
        self::assertSame('pending', get_post_meta($app->id(), '_mua_last_build_status', true));
        self::assertSame('', get_post_meta($app->id(), '_mua_last_build_error', true));
        self::assertSame('', get_post_meta($app->id(), '_mua_last_build_compare_url', true));
        self::assertStringStartsWith('build/integration-test-app-', get_post_meta($app->id(), '_mua_last_build_branch', true));

        // AS enqueue: the projection job is scheduled under our group.
        self::assertTrue(
            \function_exists('as_has_scheduled_action') && as_has_scheduled_action(
                ProjectBuildToGithub::ACTION,
                null,
                'mustuse-apps-pub'
            ),
            'ProjectBuildToGithub job should be scheduled after ship.'
        );
    }

    public function test_ship_rejects_missing_repo_url(): void
    {
        $app = App::create('No Repo App');
        self::assertInstanceOf(App::class, $app);
        wp_update_post(['ID' => $app->id(), 'post_status' => 'publish']);

        $request = new WP_REST_Request('POST', '/mustuse-apps-pub/v1/apps/' . $app->id() . '/ship');
        $response = rest_do_request($request);

        self::assertSame(500, $response->get_status());
        $data = $response->get_data();
        self::assertSame('mua_ship_error', $data['code'] ?? ($data[0]['code'] ?? ''));
    }

    public function test_ship_rejects_missing_github_token(): void
    {
        $app = App::create('No Token App');
        self::assertInstanceOf(App::class, $app);
        wp_update_post(['ID' => $app->id(), 'post_status' => 'publish']);

        $request = new WP_REST_Request('POST', '/mustuse-apps-pub/v1/apps/' . $app->id() . '/ship');
        $response = rest_do_request($request);

        self::assertSame(500, $response->get_status());
    }
}
