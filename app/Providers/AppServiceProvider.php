<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
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
        try {
            Vite::prefetch(concurrency: 3);
        } catch (\Throwable) {
            // Never block pages if the Vite manifest is missing after a deploy.
        }
    }
}
