<?php

namespace App\Providers;

use App\Models\PdfDocument;
use App\Observers\PdfDocumentObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
     */
    public function boot(): void
    {
        // Only force HTTPS when the app is configured for it. Doing this
        // unconditionally generated https:// links on plain-HTTP deployments
        // (e.g. hitting the container directly on :8000).
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });
        PdfDocument::observe(PdfDocumentObserver::class);
        Gate::define('viewPulse', fn (): bool => true);
    }
}
