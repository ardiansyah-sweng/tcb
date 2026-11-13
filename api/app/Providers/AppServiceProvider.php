<?php

namespace App\Providers;

use App\Services\TaskStateMachine;
use App\Support\SystemClock;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request or job: allowed_transitions is read at most once.
        $this->app->scoped(TaskStateMachine::class);

        $this->app->singleton(ClockInterface::class, SystemClock::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
