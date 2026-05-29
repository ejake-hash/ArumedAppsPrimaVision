<?php

namespace App\Providers;

use App\Models\Procedure;
use App\Observers\ProcedureObserver;
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
        // Mirror procedures kategori "Penunjang" → diagnostic_test_types.
        Procedure::observe(ProcedureObserver::class);
    }
}
