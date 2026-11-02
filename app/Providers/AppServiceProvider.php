<?php

namespace App\Providers;

use App\Models\User;
use App\Workflows\CustomerVerification\SanctionsRegistry;
use App\Workflows\FinancialScoring\CreditBureau;
use App\Workflows\FinancialScoring\CreditDecisionEngine;
use App\Workflows\FinancialScoring\FinancialScorecard;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SanctionsRegistry::class);
        $this->app->singleton(CreditBureau::class);
        $this->app->bind(FinancialScorecard::class, fn (): FinancialScorecard => new FinancialScorecard(config('scoring.annual_interest_rate')));
        $this->app->bind(CreditDecisionEngine::class, fn (): CreditDecisionEngine => new CreditDecisionEngine(
            config('scoring.approve_credit_score'),
            config('scoring.reject_credit_score'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('review-compliance', fn (User $user): bool => $user->isComplianceOfficer());

        Gate::define('review-credit', fn (User $user): bool => $user->isCreditOfficer());

        // Compliance and credit officers review the customers others capture, so they don't capture or edit customers themselves.
        Gate::define('capture-customers', fn (User $user): bool => $user->role === null);
    }
}
