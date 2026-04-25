<?php

namespace App\Providers;

use App\Services\WooCommerceService;
use App\Services\SlotAvailabilityService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WooCommerceService::class, function () {
            return new WooCommerceService();
        });

        $this->app->singleton(SlotAvailabilityService::class, function () {
            return new SlotAvailabilityService();
        });
    }

    public function boot(): void
    {
        RateLimiter::for('api_public', fn(Request $r) =>
            Limit::perMinute(60)->by($r->ip())
        );

        RateLimiter::for('api_auth', fn(Request $r) =>
            Limit::perMinute(300)->by($r->user()?->id ?: $r->ip())
        );

        RateLimiter::for('woocommerce', fn(Request $r) =>
            Limit::perMinute(120)->by($r->ip())
        );
    }
}
