<?php

namespace App\Providers;

use App\Models\Procedure;
use App\Observers\ProcedureObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        // Mirror procedures kategori "Penunjang" → diagnostic_test_types.
        Procedure::observe(ProcedureObserver::class);

        // Rate-limiter login (dipakai route POST /auth/login → middleware
        // 'throttle:login'). Dikunci per username+IP, BUKAN IP saja: RS memakai satu
        // IP publik untuk banyak terminal, jadi throttle per-IP akan mengunci staf
        // lain saat ganti shift. 5 percobaan/menit per (username, IP) cukup longgar
        // untuk login normal namun memblokir brute-force password default (888888).
        RateLimiter::for('login', function (Request $request) {
            $username = Str::lower((string) $request->input('username'));

            return Limit::perMinute(5)->by($username . '|' . $request->ip());
        });
    }
}
