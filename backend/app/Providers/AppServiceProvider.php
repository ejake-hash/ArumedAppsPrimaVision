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

        // Rate-limiter endpoint TULIS kiosk publik (anjungan/*). PENTING: kunci per
        // (PATH + IP), BUKAN IP saja — 'throttle:N,M' bare mengunci per (domain|IP)
        // tanpa memasukkan path, sehingga SEMUA endpoint berbagi 1 bucket per IP; RS
        // memakai satu IP publik untuk banyak kiosk (lih. limiter 'login') → pasien
        // sah bisa kena 429 di tengah alur. Dengan path di key, tiap aksi punya bucket
        // sendiri; limit dibesarkan untuk banyak kiosk di belakang satu IP.
        RateLimiter::for('kiosk', function (Request $request) {
            return Limit::perMinute(60)->by($request->path() . '|' . $request->ip());
        });

        // Registrasi perangkat TV (heartbeat ~1/menit per TV). Kunci per IP; longgar
        // agar banyak TV di belakang satu IP tak saling mengunci, tetap menahan abuse.
        RateLimiter::for('tv-register', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Penerbitan token Antrol (Mobile JKN). Kunci per IP; tahan brute-force kredensial.
        RateLimiter::for('antrol-token', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
