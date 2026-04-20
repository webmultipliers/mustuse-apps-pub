<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Support\BuildAssembler;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class BuildAssemblerTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $postMeta = [];
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        Monkey\setUp();

        $this->postMeta = [];

        Functions\when('get_post_meta')->alias(
            function (int $postId, string $key) {
                if (! isset($this->postMeta[$postId][$key])) {
                    return '';
                }
                return $this->postMeta[$postId][$key];
            }
        );

        Functions\when('update_post_meta')->alias(
            function (int $postId, string $key, mixed $value) {
                $this->postMeta[$postId][$key] = $value;
                return true;
            }
        );

        // ManifestSigner::getOrCreateKey() hits get_option/update_option; only
        // the signing key option is stubbed so other lookups (e.g. the Apple
        // Developer Team ID) return their natural default.
        Functions\when('get_option')->alias(
            static fn (string $key, mixed $default = false) => match ($key) {
                'mua_manifest_signing_key' => 'stub-signing-key',
                default                     => $default,
            }
        );
        Functions\when('update_option')->justReturn(true);

        Functions\when('get_rest_url')->alias(
            static fn ($site, string $path) => 'https://pub.test/wp-json/' . \ltrim($path, '/')
        );

        Functions\when('site_url')->justReturn('https://pub.test');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        foreach ($this->tempDirs as $dir) {
            self::deleteDir($dir);
        }
        $this->tempDirs = [];
    }

    public function test_env_for_bifrost_includes_expected_auto_generated_keys(): void
    {
        $app = $this->makeApp(42, 'news-app', [
            'deeplink_scheme'  => 'newsapp',
            'deeplink_host'    => 'news.example.com',
            'app_version_code' => 5,
        ]);

        $env = (new BuildAssembler())->envForBifrost($app);

        $byName = [];
        foreach ($env as $row) {
            self::assertArrayHasKey('name', $row);
            self::assertArrayHasKey('value', $row);
            self::assertArrayHasKey('secret', $row);
            $byName[$row['name']] = $row;
        }

        self::assertSame('com.mustuse.news-app', $byName['NATIVEPHP_APP_ID']['value']);
        self::assertSame('newsapp', $byName['NATIVEPHP_DEEPLINK_SCHEME']['value']);
        self::assertSame('news.example.com', $byName['NATIVEPHP_DEEPLINK_HOST']['value']);
        self::assertSame('5', $byName['NATIVEPHP_APP_VERSION_CODE']['value']);
        self::assertSame('https://pub.test', $byName['MUA_PUBLISHER_URL']['value']);
        self::assertStringContainsString('/apps/news-app/manifest', $byName['MUA_MANIFEST_URL']['value']);

        self::assertTrue($byName['MUA_APPKEY']['secret']);
        self::assertFalse($byName['NATIVEPHP_APP_ID']['secret']);
    }

    public function test_env_for_bifrost_merges_publisher_env_vars_overriding_autos(): void
    {
        $app = $this->makeApp(42, 'news-app', [
            'app_version_code' => 1,
            'env_vars'         => [
                ['name' => 'NATIVEPHP_APP_VERSION', 'value' => '2.1.0', 'secret' => false],
                ['name' => 'SENTRY_DSN',            'value' => 'https://abc@sentry.io/1', 'secret' => true],
            ],
        ]);

        $env = (new BuildAssembler())->envForBifrost($app);

        $byName = [];
        foreach ($env as $row) {
            $byName[$row['name']] = $row;
        }

        self::assertSame('2.1.0', $byName['NATIVEPHP_APP_VERSION']['value'], 'publisher value overrides auto-generated');
        self::assertSame('https://abc@sentry.io/1', $byName['SENTRY_DSN']['value']);
        self::assertTrue($byName['SENTRY_DSN']['secret']);
    }

    public function test_compose_env_example_writes_file_without_secret_values(): void
    {
        $buildPath = $this->makeTempDir();
        $app = $this->makeApp(42, 'news-app', [
            'app_version_code' => 3,
            'deeplink_scheme'  => 'newsapp',
            'deeplink_host'    => 'news.example.com',
        ]);

        (new BuildAssembler())->composeEnvExample($app, $buildPath);

        $envExample = $buildPath . '/.env.example';
        self::assertFileExists($envExample);
        self::assertFileDoesNotExist($buildPath . '/.env', 'BuildAssembler must not write a runtime .env');

        $contents = (string) \file_get_contents($envExample);
        self::assertStringContainsString('NATIVEPHP_APP_ID=com.mustuse.news-app', $contents);
        self::assertStringContainsString('NATIVEPHP_APP_VERSION_CODE=3', $contents);
        self::assertStringContainsString('NATIVEPHP_DEEPLINK_SCHEME=newsapp', $contents);
        self::assertStringContainsString('MUA_PUBLISHER_URL=https://pub.test', $contents);

        // MUA_APPKEY must never be materialised with a value in the committed
        // reference file — it lives in Bifrost's env UI only.
        self::assertDoesNotMatchRegularExpression('/^MUA_APPKEY=.+$/m', $contents);
    }

    public function test_bump_version_code_increments_meta_and_returns_new_value(): void
    {
        $app = $this->makeApp(42, 'slug', ['app_version_code' => 7]);

        $next = (new BuildAssembler())->bumpVersionCode($app);

        self::assertSame(8, $next);
        self::assertSame(8, $this->postMeta[42]['_mua_app_version_code']);
    }

    public function test_compose_env_example_does_not_mutate_version_code(): void
    {
        $buildPath = $this->makeTempDir();
        $app = $this->makeApp(42, 'slug', ['app_version_code' => 7]);

        (new BuildAssembler())->composeEnvExample($app, $buildPath);

        self::assertSame(7, $this->postMeta[42]['_mua_app_version_code'],
            'composeEnvExample must not mutate domain meta — bump is a separate step.');
    }

    public function test_compose_readme_writes_markdown_with_app_metadata_and_env_rows(): void
    {
        $buildPath = $this->makeTempDir();
        $app = $this->makeApp(42, 'news-app', [
            'app_version_name' => '2.1.0',
            'app_version_code' => 17,
            'deeplink_scheme'  => 'news',
            'deeplink_host'    => 'news.example.com',
        ]);

        (new BuildAssembler())->composeReadme($app, $buildPath);

        $readme = $buildPath . '/README.md';
        self::assertFileExists($readme);

        $contents = (string) \file_get_contents($readme);
        self::assertStringContainsString('# news-app', $contents);
        self::assertStringContainsString('`2.1.0`', $contents);
        self::assertStringContainsString('`17`', $contents);
        self::assertStringContainsString('`news-app`', $contents);
        self::assertStringContainsString('Auto-generated', $contents);
        self::assertStringContainsString('bifrost.nativephp.com', $contents);

        // Secret rows (MUA_APPKEY) must never materialise the plaintext value.
        self::assertStringContainsString('MUA_APPKEY', $contents);
        self::assertStringContainsString('🔒', $contents);
        self::assertStringNotContainsString('stub-signing-key', $contents);

        // Non-secret rows should render values inline.
        self::assertStringContainsString('com.mustuse.news-app', $contents);
        self::assertStringContainsString('news.example.com', $contents);
    }

    public function test_inject_manifest_writes_json_and_matching_hmac_signature(): void
    {
        // ManifestSigner::getOrCreateKey reads this option.
        Functions\when('get_option')->justReturn('test-signing-key');
        Functions\when('update_option')->justReturn(true);
        Functions\when('wp_json_encode')->alias(static fn ($v, $f = 0) => \json_encode($v, $f));

        $buildPath = $this->makeTempDir();
        $json      = '{"stable":"bytes","version":1}';

        (new BuildAssembler())->injectManifest($json, $buildPath);

        $jsonPath = $buildPath . '/storage/app/mua-manifest.json';
        $sigPath  = $buildPath . '/storage/app/mua-manifest.sig';

        self::assertFileExists($jsonPath);
        self::assertFileExists($sigPath);
        self::assertSame($json, (string) \file_get_contents($jsonPath));

        $writtenSig = \trim((string) \file_get_contents($sigPath));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $writtenSig);
        self::assertSame(
            \hash_hmac('sha256', $json, 'test-signing-key'),
            $writtenSig,
            'Signature must HMAC the exact bytes written — shell verifies with the same key.',
        );

        // Manifest must NOT be duplicated at the repo root — earlier ships
        // wrote a base_path() copy as a fallback, but storage/app/ is part
        // of the app bundle and reliably extracted on first boot.
        self::assertFileDoesNotExist($buildPath . '/mua-manifest.json');
        self::assertFileDoesNotExist($buildPath . '/mua-manifest.sig');
    }

    public function test_sync_assets_synthesises_placeholder_png_when_no_media_is_attached(): void
    {
        if (! \function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD extension not available.');
        }

        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_attached_file')->justReturn(false);

        $buildPath = $this->makeTempDir();
        $app = $this->makeApp(42, 'placeholder-app', [
            'branding' => ['primary_color' => '#2271b1'],
        ]);

        (new BuildAssembler())->syncAssets($app, $buildPath);

        self::assertFileExists($buildPath . '/public/icon.png');
        self::assertFileExists($buildPath . '/public/splash.png');

        $iconInfo = \getimagesize($buildPath . '/public/icon.png');
        self::assertIsArray($iconInfo);
        self::assertSame(1024, $iconInfo[0]);
        self::assertSame(1024, $iconInfo[1]);

        $splashInfo = \getimagesize($buildPath . '/public/splash.png');
        self::assertIsArray($splashInfo);
        self::assertSame(1080, $splashInfo[0]);
        self::assertSame(1920, $splashInfo[1]);
    }

    public function test_sync_assets_throws_when_featured_image_file_is_missing(): void
    {
        Functions\when('get_post_thumbnail_id')->justReturn(123);
        Functions\when('get_attached_file')->justReturn('/tmp/nonexistent-' . \uniqid() . '.png');

        $buildPath = $this->makeTempDir();
        $app = $this->makeApp(42, 'broken-media-app');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/declares featured image 123/');

        (new BuildAssembler())->syncAssets($app, $buildPath);
    }

    public function test_sync_assets_copies_attached_files_when_present(): void
    {
        $iconSource   = \sys_get_temp_dir() . '/mua-icon-'   . \uniqid() . '.png';
        $splashSource = \sys_get_temp_dir() . '/mua-splash-' . \uniqid() . '.png';
        // One-pixel valid PNG so copy succeeds and readers see a real image.
        $onePixel = \base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );
        \file_put_contents($iconSource,   $onePixel);
        \file_put_contents($splashSource, $onePixel);

        Functions\when('get_post_thumbnail_id')->justReturn(7);
        Functions\when('get_attached_file')->alias(
            static fn (int $id) => match ($id) {
                7  => $iconSource,
                42 => $splashSource,
                default => false,
            }
        );

        $buildPath = $this->makeTempDir();
        $app = $this->makeApp(42, 'media-app', ['splash_screen_id' => 42]);
        // Splash meta is stored under the unprefixed key by syncOrSynthesiseSplash,
        // matching how it has been written by Editorial flows since v1.
        $this->postMeta[42]['mua_splash_screen_id'] = 42;

        try {
            (new BuildAssembler())->syncAssets($app, $buildPath);

            self::assertFileExists($buildPath . '/public/icon.png');
            self::assertFileExists($buildPath . '/public/splash.png');
            self::assertSame($onePixel, (string) \file_get_contents($buildPath . '/public/icon.png'));
            self::assertSame($onePixel, (string) \file_get_contents($buildPath . '/public/splash.png'));
        } finally {
            @\unlink($iconSource);
            @\unlink($splashSource);
        }
    }

    public function test_project_components_copies_mobile_templates_under_mustuse_namespace(): void
    {
        $blocksRoot = $this->makeTempDir();
        $this->seedBlock($blocksRoot, 'hero', "<div>hero template</div>\n");
        $this->seedBlock($blocksRoot, 'article-list', "<div>list</div>\n");
        // An editor-only block without a mobile template — must NOT be projected.
        \mkdir($blocksRoot . '/canvas', 0755, true);
        \file_put_contents($blocksRoot . '/canvas/block.json', '{}');

        $buildPath = $this->makeTempDir();
        $assembler = new class ($blocksRoot) extends BuildAssembler {
            public function __construct(private readonly string $fixtureBlocksRoot)
            {
            }
            protected function blocksDirectory(): string
            {
                return $this->fixtureBlocksRoot;
            }
        };

        $projected = $assembler->projectComponents($buildPath);

        \sort($projected);
        self::assertSame(['article-list', 'hero'], $projected);

        $target = $buildPath . '/resources/views/components/mustuse';
        self::assertFileExists($target . '/hero.blade.php');
        self::assertFileExists($target . '/article-list.blade.php');
        self::assertFileDoesNotExist($target . '/canvas.blade.php');

        self::assertStringContainsString('hero template', (string) \file_get_contents($target . '/hero.blade.php'));
    }

    public function test_project_components_skips_invalid_slugs(): void
    {
        $blocksRoot = $this->makeTempDir();
        // Leading dot and uppercase are both rejected.
        \mkdir($blocksRoot . '/.hidden', 0755, true);
        \file_put_contents($blocksRoot . '/.hidden/mobile.blade.php', 'nope');
        \mkdir($blocksRoot . '/Bad_Name', 0755, true);
        \file_put_contents($blocksRoot . '/Bad_Name/mobile.blade.php', 'nope');
        $this->seedBlock($blocksRoot, 'valid-one', 'ok');

        $buildPath = $this->makeTempDir();
        $assembler = new class ($blocksRoot) extends BuildAssembler {
            public function __construct(private readonly string $fixtureBlocksRoot)
            {
            }
            protected function blocksDirectory(): string
            {
                return $this->fixtureBlocksRoot;
            }
        };

        self::assertSame(['valid-one'], $assembler->projectComponents($buildPath));
    }

    public function test_project_block_assets_copies_newest_per_variant_from_dist_with_stable_names(): void
    {
        $blocksRoot = $this->makeTempDir();
        // A block with multiple timestamped builds in Blockstudio's `_dist/`.
        // projectBlockAssets must pick the NEWEST per variant and land it
        // at the stable filename the shell layout references.
        \mkdir($blocksRoot . '/hero/_dist', 0755, true);
        \file_put_contents($blocksRoot . '/hero/block.json', '{}');
        \file_put_contents($blocksRoot . '/hero/_dist/styles.inline-1776000000.css', '/* old */');
        \file_put_contents($blocksRoot . '/hero/_dist/styles.inline-1776000999.css', '/* new */');
        \file_put_contents($blocksRoot . '/hero/_dist/styles-1776000500.css',        '/* link */');
        \file_put_contents($blocksRoot . '/hero/_dist/scripts-1776000700.js',        'console.log("x")');
        // Files not matching `{variant}-{ts}.{ext}` are ignored.
        \file_put_contents($blocksRoot . '/hero/_dist/random.txt',                    'junk');

        // A block that doesn't ship any dist/ at all — silently skipped.
        \mkdir($blocksRoot . '/text', 0755, true);
        \file_put_contents($blocksRoot . '/text/block.json', '{}');

        $buildPath = $this->makeTempDir();
        $assembler = new class ($blocksRoot) extends BuildAssembler {
            public function __construct(private readonly string $fixtureBlocksRoot)
            {
            }
            protected function blocksDirectory(): string
            {
                return $this->fixtureBlocksRoot;
            }
        };

        $projected = $assembler->projectBlockAssets($buildPath);

        self::assertSame(['hero'], $projected);

        $target = $buildPath . '/public/blocks/hero';
        self::assertFileExists($target . '/styles.inline.css');
        self::assertFileExists($target . '/styles.css');
        self::assertFileExists($target . '/scripts.js');
        self::assertFileDoesNotExist($target . '/random.txt');

        // Newest timestamp wins per variant.
        self::assertSame('/* new */', \file_get_contents($target . '/styles.inline.css'));
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

    private function makeTempDir(): string
    {
        $dir = \sys_get_temp_dir() . '/mua-assembler-test-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function seedBlock(string $blocksRoot, string $slug, string $content): void
    {
        \mkdir($blocksRoot . '/' . $slug, 0755, true);
        \file_put_contents($blocksRoot . '/' . $slug . '/block.json', '{}');
        \file_put_contents($blocksRoot . '/' . $slug . '/mobile.blade.php', $content);
    }

    private static function deleteDir(string $dir): void
    {
        if (! \is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path) {
            /** @var \SplFileInfo $path */
            if ($path->isDir()) {
                @\rmdir($path->getPathname());
            } else {
                @\unlink($path->getPathname());
            }
        }
        @\rmdir($dir);
    }
}
