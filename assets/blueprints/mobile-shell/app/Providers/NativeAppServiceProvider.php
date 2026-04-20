<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Shell-side application provider.
 *
 * NativePHP v3 Mobile has no Window/MenuBar/BottomNav facades — the top bar
 * and bottom nav are EDGE Blade components that render at the view layer
 * (see native-edge.blade.php). The manifest is loaded per-request by the
 * NativeEdge Livewire component rather than at boot, so this provider stays
 * minimal; downstream publishers can override it if they want to share the
 * manifest into the service container or register custom event listeners.
 */
class NativeAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->ensureAppKey();
    }

    public function boot(): void
    {
        //
    }

    /**
     * Capability plugins registered for this shell.
     *
     * NativePHP v3 requires explicit per-app registration of any plugin
     * the manifest declares (camera, scanner, biometrics, push, etc.) —
     * Composer auto-discovery is intentionally bypassed so apps don't
     * inherit unwanted native code from transitive dependencies.
     *
     * Default-empty: the demo manifests in this blueprint don't need any
     * native plugins. Publishers extend this list when their manifest
     * declares a `requiredCapabilities` entry.
     *
     * @return list<class-string>
     */
    public function plugins(): array
    {
        return [];
    }

    /**
     * Self-heal a missing APP_KEY on-device.
     *
     * Bifrost is expected to inject APP_KEY via its environment UI, but
     * real deployments occasionally ship without one — and every request
     * through the `web` middleware group then 500s in EncryptCookies /
     * Livewire CSRF. Persist a device-local key in storage so sessions
     * survive across requests, and only generate once on first boot.
     */
    private function ensureAppKey(): void
    {
        $config  = $this->app->make('config');
        $current = (string) $config->get('app.key', '');
        if ($current !== '') {
            return;
        }

        $keyFile = storage_path('app/.app-key');
        if (is_file($keyFile)) {
            $stored = trim((string) @file_get_contents($keyFile));
            if ($stored !== '') {
                $config->set('app.key', $stored);
                return;
            }
        }

        try {
            $generated = 'base64:' . base64_encode(random_bytes(32));
        } catch (\Throwable) {
            // random_bytes can throw if the CSPRNG is unavailable — fall
            // back to an in-memory ephemeral key so the shell still boots.
            // Sessions won't survive a restart, but the alternative is a
            // fatal error every request.
            $generated = 'base64:' . base64_encode(str_repeat("\0", 32));
        }

        @file_put_contents($keyFile, $generated);
        @chmod($keyFile, 0600);
        $config->set('app.key', $generated);
    }
}
