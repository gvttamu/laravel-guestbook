<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\CustomerController;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_requests_without_a_token_are_rejected(): void
    {
        $customer = Customer::factory()->create();

        $this->getJson("/api/v1/customers/{$customer->id}")->assertUnauthorized();
        $this->getJson('/api/v1/customers')->assertUnauthorized();
    }

    public function test_tokens_without_the_read_ability_are_forbidden(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs(User::factory()->create(), ['something:else']);

        $this->getJson("/api/v1/customers/{$customer->id}")->assertForbidden();
    }

    public function test_show_returns_the_customer_to_a_real_bearer_token(): void
    {
        $customer = Customer::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'date_of_birth' => '1990-12-10',
            'address_line1' => '12 St James Square',
            'address_line2' => null,
            'city' => 'London',
            'monthly_income' => 4200,
            'car_payments' => null,
            'loan_amount' => 15000,
            'loan_term_months' => 48,
            'loan_purpose' => 'home_improvement',
        ]);
        $token = User::factory()->create()->createToken('Workflow engine', [CustomerController::READ_ABILITY]);

        $this->withToken($token->plainTextToken)
            ->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('id', $customer->id)
            ->assertJsonPath('first_name', 'Ada')
            ->assertJsonPath('last_name', 'Lovelace')
            ->assertJsonPath('email', 'ada@example.com')
            ->assertJsonPath('date_of_birth', '1990-12-10')
            ->assertJsonPath('address.line1', '12 St James Square')
            ->assertJsonPath('address.line2', null)
            ->assertJsonPath('address.city', 'London')
            ->assertJsonPath('financials.monthly_income', '4200.00')
            ->assertJsonPath('financials.car_payments', null)
            ->assertJsonPath('loan', ['amount' => '15000.00', 'term_months' => 48, 'purpose' => 'home_improvement'])
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('version');
    }

    public function test_show_returns_not_found_for_an_unknown_customer(): void
    {
        Sanctum::actingAs(User::factory()->create(), [CustomerController::READ_ABILITY]);

        $this->getJson('/api/v1/customers/999')->assertNotFound();
    }

    public function test_index_pages_customers_and_filters_by_email(): void
    {
        Customer::factory()->count(26)->create();
        $ada = Customer::factory()->create(['email' => 'ada@example.com']);
        Sanctum::actingAs(User::factory()->create(), [CustomerController::READ_ABILITY]);

        $this->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.total', 27)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/v1/customers?email=ada@example.com')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ada->id);
    }
}
