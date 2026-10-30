<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
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
        Gate::define('review-compliance', fn (User $user): bool => $user->isComplianceOfficer());

        // Compliance officers review the customers others capture, so they don't capture or edit customers themselves.
        Gate::define('capture-customers', fn (User $user): bool => ! $user->isComplianceOfficer());

        Http::macro('flowable', fn (): PendingRequest => Http::baseUrl(config('services.flowable.url'))
            ->withBasicAuth(config('services.flowable.username'), config('services.flowable.password'))
            ->acceptJson()
            ->timeout(10));
    }
}
