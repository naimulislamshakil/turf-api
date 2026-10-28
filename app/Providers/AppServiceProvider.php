<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use App\Contracts\SmsGateway;
use App\Services\NullSmsGateway;
use App\Services\MramSmsGateway;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmsGateway::class, MramSmsGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('admin-login', fn ($request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('owner-login', fn ($request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('unified-login', fn ($request) => Limit::perMinute(10)->by(Str::lower(trim((string) $request->input('login'))).'|'.$request->ip()));
    }
}
