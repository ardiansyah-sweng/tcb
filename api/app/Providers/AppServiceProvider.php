<?php

namespace App\Providers;

use App\Services\TaskStateMachine;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request or job: allowed_transitions is read at most once.
        $this->app->scoped(TaskStateMachine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
