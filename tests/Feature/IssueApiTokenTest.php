<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerVerificationController;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class IssueApiTokenTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_issues_a_customer_read_token_for_a_service_account(): void
    {
        $this->artisan('api:issue-token', ['name' => 'Workflow engine'])
            ->expectsOutputToContain('API token created for Workflow engine (customers:read).')
            ->assertSuccessful();

        $serviceUser = User::sole();
        $this->assertSame('Workflow engine', $serviceUser->name);
        $this->assertSame('workflow-engine@service.invalid', $serviceUser->email);

        $token = PersonalAccessToken::sole();
        $this->assertTrue($token->tokenable->is($serviceUser));
        $this->assertSame([CustomerController::READ_ABILITY], $token->abilities);
    }

    public function test_reuses_the_service_account_for_further_tokens(): void
    {
        $this->artisan('api:issue-token', ['name' => 'Workflow engine'])->assertSuccessful();
        $this->artisan('api:issue-token', ['name' => 'Workflow engine'])->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertSame(2, User::sole()->tokens()->count());
    }

    public function test_the_verify_option_also_allows_reporting_verification_results(): void
    {
        $this->artisan('api:issue-token', ['name' => 'Workflow engine', '--verify' => true])->assertSuccessful();

        $this->assertSame(
            [CustomerController::READ_ABILITY, CustomerVerificationController::VERIFY_ABILITY],
            PersonalAccessToken::sole()->abilities,
        );
    }
}
