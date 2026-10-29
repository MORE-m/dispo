<?php

namespace App\Providers;

use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\StandardOffer;
use App\Models\StandardOfferVersion;
use App\Models\User;
use App\Policies\CalculationPolicy;
use App\Policies\DispoOrderPolicy;
use App\Policies\StandardOfferPolicy;
use App\Services\OnlineAudio\Import\OaTkpPriceListYearLock;
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
        // PO-CALC-TKP-1D4c-4c: eine Lock-Instanz pro Request, damit AdminWriter →
        // DraftImportGuard / Activation denselben reentrant-Zustand teilen.
        $this->app->singleton(OaTkpPriceListYearLock::class);
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

        Gate::define('manage-special-approve-rights', function (User $user): bool {
            return $user->canManageSpecialApproveRights();
        });

        Gate::define('manage-users', function (User $user): bool {
            return $user->canManageUsers();
        });

        Gate::define('view-notification-outbox', function (User $user): bool {
            return $user->canViewNotificationOutbox();
        });

        Gate::define('manage-oa-tkp-master-data', function (User $user): bool {
            return $user->canManageOaTkpMasterData();
        });

        Gate::define('import-crm-accounts', function (User $user): bool {
            return $user->canImportCrmAccounts();
        });

        Gate::define('create-provisional-crm-accounts', function (User $user): bool {
            return $user->canCreateProvisionalCrmAccounts();
        });

        Gate::define('manage-crm-matches', function (User $user): bool {
            return $user->canManageCrmMatches();
        });

        Gate::define('view-crm-accounts', function (User $user): bool {
            return $user->canViewCrmAccounts();
        });

        Gate::policy(Calculation::class, CalculationPolicy::class);
        Gate::policy(DispoOrder::class, DispoOrderPolicy::class);
        Gate::policy(StandardOffer::class, StandardOfferPolicy::class);
        Gate::policy(StandardOfferVersion::class, StandardOfferPolicy::class);
    }
}
