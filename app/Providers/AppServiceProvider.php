<?php

namespace App\Providers;

use App\Models\Influencer;
use App\Observers\InfluencerObserver;
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
        // Register the observer for the Influencer model
        Influencer::observe(InfluencerObserver::class);
    }
}
