<?php

namespace App\Providers;

use App\Models\PdfDocument;
use App\Models\User;
use App\Observers\PdfDocumentObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

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
        URL::forceScheme('https');
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });
        PdfDocument::observe(PdfDocumentObserver::class);
        Gate::define('viewPulse', function (User $user) {
            return true;
        });
    }
}
