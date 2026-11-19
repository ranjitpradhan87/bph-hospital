<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Services\PublicSite;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
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
        // Every public page gets the hospital settings, stats and department list for header/footer.
        // Scoped: built once per request (the page and its layout both ask for it), never shared between requests.
        $this->app->scoped('site.data', function () {
            $s = PublicSite::settings();

            return ['hospital' => $s['settings']['hospital'] ?? [], 'content' => $s['settings']['content'] ?? [], 'stats' => $s['stats'],
                'registration' => $s['settings']['registration'] ?? [], 'departments' => PublicSite::departments()];
        });
        View::composer(['site.*', 'layouts.site'], fn ($view) => $view->with('site', $this->app->make('site.data')));

        // Named limiters keep separate counters; stacked `throttle:n,m` middleware would share one.
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(300)->by('public|'.$r->ip()));
        RateLimiter::for('guest-booking', fn (Request $r) => Limit::perHour(10)->by('guest-booking|'.$r->ip()));
        RateLimiter::for('set-password', fn (Request $r) => Limit::perMinute(10)->by('set-password|'.$r->ip()));
        RateLimiter::for('contact', fn (Request $r) => Limit::perHour(5)->by('contact|'.$r->ip()));
    }
}
