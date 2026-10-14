<?php

namespace App\Providers;

use App\Services\Marketing\AdRenderer;
use App\Services\Marketing\AdRendererClient;
use App\Services\Marketing\MarketingAi;
use App\Services\Marketing\MarketingAiClient;
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
        // MK-8: the marketing Actions ask an interface for copy, so the model behind it can change in one place.
        $this->app->bind(MarketingAiClient::class, MarketingAi::class);
        // MK-5: ads are rendered through an interface, so tests swap in a fake and never start a browser.
        $this->app->bind(AdRendererClient::class, AdRenderer::class);
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
