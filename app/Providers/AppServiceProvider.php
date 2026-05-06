<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\Auth\CachedPermissionCache;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Sakemaru\Auth\Services\PermissionCache;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PermissionCache::class, CachedPermissionCache::class);

        $this->app->booted(function () {
            $this->app['queue']->addConnector('custom-queue', function () {
                return new \App\Queue\DatabaseWithCustomQueueConnector($this->app['db']);
            });
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Use custom PersonalAccessToken model with sakemaru connection
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
