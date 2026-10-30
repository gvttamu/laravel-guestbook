<?php

namespace Database\Factories;

use App\Http\Controllers\CustomerDraftController;
use App\Models\Customer;
use App\Models\FormDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

/**
 * @extends Factory<FormDraft>
 */
class FormDraftFactory extends Factory
{
    /**
     * Define the model's default state: an empty customer draft.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_type' => CustomerDraftController::FORM_TYPE,
            'user_id' => User::factory(),
            'schema_version' => CustomerDraftController::SCHEMA_VERSION,
            'payload' => [],
            'current_step' => 'basic',
            'version' => 1,
        ];
    }

    /**
     * Indicate that every customer field has been filled in.
     */
    public function completeCustomer(): static
    {
        return $this->state(fn (): array => [
            'payload' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.com',
                'phone' => '+44 20 7946 0000',
                'date_of_birth' => '1990-12-10',
                'address_line1' => '12 St James Square',
                'address_line2' => null,
                'city' => 'London',
                'postal_code' => 'SW1Y 4JH',
                'country' => 'United Kingdom',
                'monthly_income' => 4200,
                'other_income' => null,
                'rent_or_mortgage' => 1350.5,
                'car_payments' => 300,
                'other_debt_payments' => null,
                'living_expenses' => 900,
                'loan_amount' => 15000,
                'loan_term_months' => 48,
                'loan_purpose' => 'home_improvement',
                'loan_purpose_details' => 'New kitchen',
            ],
            'current_step' => 'loan',
        ]);
    }

    /**
     * Indicate that the draft edits the given customer, starting from its saved details, with the given changes made.
     *
     * @param  array<string, mixed>  $changes
     */
    public function editing(Customer $customer, array $changes = []): static
    {
        $customer = $customer->fresh();
        $saved = Arr::only($customer->toArray(), $customer->getFillable());

        return $this->state(fn (): array => [
            'customer_id' => $customer->id,
            'customer_version' => $customer->version,
            'payload' => [...$saved, ...$changes],
            'original_payload' => $saved,
        ]);
    }
}
