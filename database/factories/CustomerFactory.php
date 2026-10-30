<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('+1 ### ### ####'),
            'date_of_birth' => fake()->optional()->date(max: '-18 years'),
            'address_line1' => fake()->streetAddress(),
            'address_line2' => null,
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'country' => fake()->country(),
            'monthly_income' => fake()->numberBetween(2000, 9000),
            'other_income' => null,
            'rent_or_mortgage' => fake()->numberBetween(500, 2000),
            'car_payments' => fake()->optional()->numberBetween(150, 600),
            'other_debt_payments' => fake()->optional()->numberBetween(50, 400),
            'living_expenses' => fake()->numberBetween(400, 1500),
            'loan_amount' => fake()->numberBetween(1000, 50000),
            'loan_term_months' => fake()->randomElement([12, 24, 36, 48, 60]),
            'loan_purpose' => fake()->randomElement(['home_improvement', 'car', 'debt_consolidation']),
            'loan_purpose_details' => null,
        ];
    }
}
