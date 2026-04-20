<?php

declare(strict_types=1);

namespace MustUse\Pub\Tests\Integration\Api;

use MustUse\Pub\Api\WellKnown\AndroidAssetLinks;
use MustUse\Pub\Api\WellKnown\AppleAppSiteAssociation;
use MustUse\Pub\Data\Models\App;
use MustUse\Pub\Tests\Integration\MuaIntegrationTestCase;

/**
 * Well-known payload shape tests.
 *
 * Apple and Google fetch the files unauthenticated at fixed domain-root
 * paths. Incorrect payload shapes silently break universal/app links — a
 * class of bug the old `/wp-json/` mount already exhibited. These tests
 * freeze: (a) only apps whose deeplink_host matches the site host are
 * included, (b) apps missing the platform identifier are skipped, and (c)
 * the exact shape of each emitted statement.
 *
 * `listen()` calls exit — we test the pure `buildPayload()` extraction so
 * PHPUnit doesn't die mid-run.
 */
final class WellKnownTest extends MuaIntegrationTestCase {
	private string $host = '';

	public function set_up(): void {
		parent::set_up();
		$this->host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	private function makeApp( string $title, array $meta ): App {
		$app = App::create( $title );
		self::assertInstanceOf( App::class, $app );
		// App::create saves as draft; AASA/assetlinks only return published.
		wp_update_post( [ 'ID' => $app->id(), 'post_status' => 'publish' ] );
		foreach ( $meta as $key => $value ) {
			$app->updateMeta( $key, $value );
		}
		$reloaded = App::find( $app->id() );
		self::assertInstanceOf( App::class, $reloaded );
		return $reloaded;
	}

	public function test_apple_payload_is_empty_when_no_apps_match_host(): void {
		$this->makeApp( 'No deeplink', [ 'bundle_id' => 'com.example.app' ] );

		$payload = AppleAppSiteAssociation::buildPayload();

		self::assertArrayHasKey( 'applinks', $payload );
		self::assertSame( [], $payload['applinks']['apps'] );
		self::assertSame( [], $payload['applinks']['details'] );
	}

	public function test_apple_payload_emits_team_prefixed_app_id_when_team_set(): void {
		$this->makeApp( 'With team', [
			'deeplink_host' => $this->host,
			'bundle_id'     => 'com.example.app',
			'apple_team_id' => 'TEAM12345',
		] );

		$payload = AppleAppSiteAssociation::buildPayload();

		self::assertCount( 1, $payload['applinks']['details'] );
		self::assertSame( 'TEAM12345.com.example.app', $payload['applinks']['details'][0]['appID'] );
		self::assertSame( [ '*' ], $payload['applinks']['details'][0]['paths'] );
	}

	public function test_apple_payload_emits_bare_bundle_when_no_team(): void {
		$this->makeApp( 'No team', [
			'deeplink_host' => $this->host,
			'bundle_id'     => 'com.example.app',
		] );

		$payload = AppleAppSiteAssociation::buildPayload();

		self::assertCount( 1, $payload['applinks']['details'] );
		self::assertSame( 'com.example.app', $payload['applinks']['details'][0]['appID'] );
	}

	public function test_apple_payload_skips_apps_missing_bundle_id(): void {
		$this->makeApp( 'No bundle', [
			'deeplink_host' => $this->host,
		] );
		$this->makeApp( 'Has bundle', [
			'deeplink_host' => $this->host,
			'bundle_id'     => 'com.example.bundled',
		] );

		$payload = AppleAppSiteAssociation::buildPayload();

		self::assertCount( 1, $payload['applinks']['details'] );
		self::assertSame( 'com.example.bundled', $payload['applinks']['details'][0]['appID'] );
	}

	public function test_apple_payload_skips_apps_on_different_host(): void {
		$this->makeApp( 'Other host', [
			'deeplink_host' => 'other.example.com',
			'bundle_id'     => 'com.example.elsewhere',
		] );
		$this->makeApp( 'This host', [
			'deeplink_host' => $this->host,
			'bundle_id'     => 'com.example.here',
		] );

		$payload = AppleAppSiteAssociation::buildPayload();

		self::assertCount( 1, $payload['applinks']['details'] );
		self::assertSame( 'com.example.here', $payload['applinks']['details'][0]['appID'] );
	}

	public function test_android_payload_empty_when_no_apps(): void {
		self::assertSame( [], AndroidAssetLinks::buildPayload() );
	}

	public function test_android_payload_emits_full_statement(): void {
		$this->makeApp( 'Android app', [
			'deeplink_host'               => $this->host,
			'android_package_name'        => 'com.example.droid',
			'android_sha256_fingerprints' => [ 'AA:BB:CC', 'DD:EE:FF' ],
		] );

		$payload = AndroidAssetLinks::buildPayload();

		self::assertCount( 1, $payload );
		self::assertSame( [ 'delegate_permission/common.handle_all_urls' ], $payload[0]['relation'] );
		self::assertSame( 'android_app', $payload[0]['target']['namespace'] );
		self::assertSame( 'com.example.droid', $payload[0]['target']['package_name'] );
		self::assertSame( [ 'AA:BB:CC', 'DD:EE:FF' ], $payload[0]['target']['sha256_cert_fingerprints'] );
	}

	public function test_android_payload_skips_apps_missing_package_name(): void {
		$this->makeApp( 'No package', [
			'deeplink_host' => $this->host,
		] );
		$this->makeApp( 'Has package', [
			'deeplink_host'        => $this->host,
			'android_package_name' => 'com.example.droid',
		] );

		$payload = AndroidAssetLinks::buildPayload();

		self::assertCount( 1, $payload );
		self::assertSame( 'com.example.droid', $payload[0]['target']['package_name'] );
	}

	public function test_android_payload_accepts_apps_with_empty_fingerprints(): void {
		$this->makeApp( 'Missing fingerprints', [
			'deeplink_host'        => $this->host,
			'android_package_name' => 'com.example.droid',
		] );

		$payload = AndroidAssetLinks::buildPayload();

		self::assertCount( 1, $payload );
		self::assertSame( [], $payload[0]['target']['sha256_cert_fingerprints'] );
	}
}
