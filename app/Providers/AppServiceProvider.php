<?php

namespace App\Providers;

use App\Services\ClaudeTaskExecutor;
use App\Services\PlaceholderTaskExecutor;
use App\Services\TaskExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Without an API key the app still runs end to end on placeholder
        // output, so the gates and the audit record can be demonstrated
        // without spending anything.
        $this->app->bind(TaskExecutor::class, function () {
            $configured = config('claude.enabled') && filled(config('claude.api_key'));

            return $this->app->make(
                $configured ? ClaudeTaskExecutor::class : PlaceholderTaskExecutor::class
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
