<?php

namespace Database\Seeders;

use App\Models\FormDraft;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Complete new-customer drafts, ready to submit, that each exercise one outcome of the
 * workflows: a sanctions match on the address, phone or email (against the demo list in
 * sanctions.json), or a high, medium or low financial score (at the default 12% rate).
 */
class FormDraftSeeder extends Seeder
{
    /**
     * Each scenario's changes to a clear, affordable customer.
     *
     * @var array<string, array<string, mixed>>
     */
    public const SCENARIOS = [
        'sanctioned address' => [
            'first_name' => 'Sam',
            'last_name' => 'Shadow',
            'email' => 'sam.shadow@example.com',
            'phone' => '+1 555 010 0100',
            'address_line1' => '666 Shadow Lane',
            'city' => 'Springfield',
            'postal_code' => '66666',
            'country' => 'United States',
        ],
        'sanctioned phone' => [
            'first_name' => 'Petra',
            'last_name' => 'Phone',
            'email' => 'petra.phone@example.com',
            'phone' => '+44 20 7946 0999',
        ],
        'sanctioned email' => [
            'first_name' => 'Blake',
            'last_name' => 'Blocked',
            'email' => 'blocked@example.com',
        ],
        // Repayments take 24% of income and over half is left: scores 100, low risk.
        'high score' => [
            'first_name' => 'Hannah',
            'last_name' => 'High',
            'email' => 'hannah.high@example.com',
        ],
        // Repayments take 38% of income and 14% is left: scores 60, medium risk.
        'medium score' => [
            'first_name' => 'Morgan',
            'last_name' => 'Medium',
            'email' => 'morgan.medium@example.com',
            'monthly_income' => 4000,
            'rent_or_mortgage' => 1200,
            'car_payments' => 200,
            'living_expenses' => 1900,
            'loan_amount' => 5000,
            'loan_term_months' => 48,
        ],
        // Repayments take 50% of income and 4% is left: scores 30, high risk.
        'low score' => [
            'first_name' => 'Lee',
            'last_name' => 'Low',
            'email' => 'lee.low@example.com',
            'monthly_income' => 3000,
            'rent_or_mortgage' => 1100,
            'car_payments' => 250,
            'living_expenses' => 1400,
            'loan_amount' => 3000,
            'loan_term_months' => 24,
        ],
    ];

    /**
     * A customer who is neither sanctioned nor stretched, which each scenario changes.
     *
     * @var array<string, mixed>
     */
    private const CLEAR_CUSTOMER = [
        'phone' => '+44 20 7946 0100',
        'date_of_birth' => '1985-04-12',
        'address_line1' => '12 St James Square',
        'address_line2' => null,
        'city' => 'London',
        'postal_code' => 'SW1Y 4JH',
        'country' => 'United Kingdom',
        'monthly_income' => 6000,
        'other_income' => null,
        'rent_or_mortgage' => 1200,
        'car_payments' => null,
        'other_debt_payments' => null,
        'living_expenses' => 1500,
        'loan_amount' => 10000,
        'loan_term_months' => 60,
        'loan_purpose' => 'home_improvement',
        'loan_purpose_details' => null,
    ];

    /**
     * Seed a draft of each scenario for the given user, who can then submit them from their inbox.
     */
    public function run(User $user): void
    {
        foreach (self::SCENARIOS as $changes) {
            FormDraft::factory()->for($user)->create([
                'payload' => [...self::CLEAR_CUSTOMER, ...$changes],
                'current_step' => 'loan',
            ]);
        }
    }
}
