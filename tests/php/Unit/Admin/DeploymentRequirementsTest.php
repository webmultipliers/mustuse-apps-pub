<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Admin\DeploymentRequirements;
use MustUse\Pub\Data\Models\App;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class DeploymentRequirementsTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $postMeta = [];

    protected function setUp(): void
    {
        Monkey\setUp();
        $this->postMeta = [];

        Functions\when('get_option')->justReturn('');
        Functions\when('update_option')->justReturn(true);
        Functions\when('__')->returnArg(1);
        Functions\when('get_posts')->justReturn([]);
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value, mixed ...$args) => $value
        );
        Functions\when('get_post_meta')->alias(
            function (int $postId, string $key) {
                if (! isset($this->postMeta[$postId][$key])) {
                    return '';
                }
                return $this->postMeta[$postId][$key];
            }
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_returns_checks_for_all_five_nodes(): void
    {
        $app    = $this->makeApp(42, 'my-app');
        $checks = DeploymentRequirements::checksForApp($app);

        self::assertNotEmpty($checks);
        $nodes = \array_unique(\array_column($checks, 'node'));
        \sort($nodes);
        self::assertSame([1, 2, 3, 4, 5], $nodes, 'Every node must have at least one check.');
    }

    public function test_each_check_has_required_shape(): void
    {
        $app    = $this->makeApp(42, 'my-app');
        $checks = DeploymentRequirements::checksForApp($app);

        foreach ($checks as $i => $check) {
            self::assertArrayHasKey('label', $check, "Check {$i} missing label.");
            self::assertArrayHasKey('status', $check, "Check {$i} missing status.");
            self::assertArrayHasKey('node', $check, "Check {$i} missing node.");
            self::assertContains($check['status'], ['pass', 'fail', 'info'], "Check {$i} has invalid status '{$check['status']}'.");
            self::assertGreaterThanOrEqual(1, $check['node']);
            self::assertLessThanOrEqual(5, $check['node']);
        }
    }

    public function test_screens_check_fails_when_no_screens_exist(): void
    {
        Functions\when('get_posts')->justReturn([]);
        $app    = $this->makeApp(42, 'my-app');
        $checks = DeploymentRequirements::checksForApp($app);

        $node1 = \array_filter($checks, fn ($c) => $c['node'] === 1);
        $screenCheck = \array_values(\array_filter($node1, fn ($c) => \str_contains($c['label'], 'screen')))[0] ?? null;

        self::assertNotNull($screenCheck);
        self::assertSame('fail', $screenCheck['status']);
    }

    public function test_screens_check_passes_when_screens_exist(): void
    {
        $screenPost = WP_Post::make([
            'ID'          => 200,
            'post_name'   => 'home',
            'post_title'  => 'Home',
            'post_status' => 'publish',
        ]);
        Functions\when('get_posts')->justReturn([$screenPost]);
        $app    = $this->makeApp(42, 'my-app');
        $checks = DeploymentRequirements::checksForApp($app);

        $node1 = \array_filter($checks, fn ($c) => $c['node'] === 1);
        $screenCheck = \array_values(\array_filter($node1, fn ($c) => \str_contains($c['label'], 'screen')))[0] ?? null;

        self::assertNotNull($screenCheck);
        self::assertSame('pass', $screenCheck['status']);
    }

    public function test_github_checks_fail_when_app_meta_missing(): void
    {
        $app    = $this->makeApp(42, 'my-app');
        $checks = DeploymentRequirements::checksForApp($app);

        $node3 = \array_filter($checks, fn ($c) => $c['node'] === 3);
        foreach ($node3 as $check) {
            self::assertSame('fail', $check['status']);
        }
    }

    public function test_github_checks_pass_when_app_meta_set(): void
    {
        $app = $this->makeApp(42, 'my-app', [
            'build_repo_url' => 'https://github.com/owner/repo.git',
            'github_token'   => 'v1.encrypted',
        ]);
        $checks = DeploymentRequirements::checksForApp($app);

        $node3 = \array_values(\array_filter($checks, fn ($c) => $c['node'] === 3));
        foreach ($node3 as $check) {
            self::assertSame('pass', $check['status']);
        }
    }

    public function test_filter_hook_extends_checks(): void
    {
        $extra = ['label' => 'APNs cert uploaded', 'status' => 'pass', 'node' => 3];
        Functions\when('apply_filters')->alias(
            static function (string $hook, mixed $value) use ($extra) {
                if ($hook === 'mua_app_requirements_check') {
                    $value[] = $extra;
                }
                return $value;
            }
        );

        $app    = $this->makeApp(42, 'my-app');
        $checks = DeploymentRequirements::checksForApp($app);

        $labels = \array_column($checks, 'label');
        self::assertContains('APNs cert uploaded', $labels);
    }

    private function makeApp(int $id, string $slug, array $meta = []): App
    {
        $this->postMeta[$id] = ['_mua_app_type' => 'mobile_ios'];
        foreach ($meta as $key => $value) {
            $this->postMeta[$id]['_mua_' . $key] = $value;
        }

        return new App(WP_Post::make([
            'ID'          => $id,
            'post_name'   => $slug,
            'post_title'  => $slug,
            'post_status' => 'publish',
        ]));
    }
}
