<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * The mobile shell is served from http://127.0.0.1 by Bifrost's
     * embedded PHP runtime, so HTTPS enforcement is never correct here.
     * A previous version called URL::forceHttps() and crashed at boot
     * because native.php runs bootstrap() before a `request` binding
     * exists, constructing UrlGenerator with null and throwing.
     */
    public function boot(): void
    {
        //
    }
}
