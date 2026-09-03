<?php

namespace App\Providers;

use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\User;
use App\Policies\CalculationPolicy;
use App\Policies\DispoOrderPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configureDefaults();
        $this->configureAuthorization();
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

    /**
     * AUTH-001, AUTH-003: rollenbasierte Gates. Fachliche Policies folgen je Modul.
     */
    protected function configureAuthorization(): void
    {
        Gate::define('access-administration', function (User $user): bool {
            return $user->canAccessAdministration();
        });

        Gate::policy(Calculation::class, CalculationPolicy::class);
        Gate::policy(DispoOrder::class, DispoOrderPolicy::class);
    }
}
