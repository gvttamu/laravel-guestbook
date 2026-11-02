<?php

namespace Database\Seeders;

use App\Models\FormDraft;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Complete new-customer drafts, ready to submit, that each exercise one outcome of the
 * workflows: a sanctions match on the address, phone or email (against the demo list in
 * sanctions.json); a high, medium or low affordability score (at the default 12% rate);
 * or a poor, fair or missing credit score (in the demo credit_bureau.json). With the
 * default thresholds the decision engine approves the sanctioned customers and the high
 * score, rejects the low score and poor credit, and refers the rest to a credit officer:
 * the medium score and fair credit in the low credit risk tier, no credit file in the medium
 * tier, the large loan in the high tier, and the business loan in the highest.
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
        // Repayments take 24% of income and over half is left: scores 100, low risk. Credit score 780: approved.
        'high score' => [
            'first_name' => 'Hannah',
            'last_name' => 'High',
            'email' => 'hannah.high@example.com',
        ],
        // Repayments take 38% of income and 14% is left: scores 60, medium risk. Credit score 720: referred, low credit risk.
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
        // Repayments take 50% of income and 4% is left: scores 30, high risk. Credit score 650: rejected.
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
        // Affordable, but credit score 540: rejected.
        'poor credit' => [
            'first_name' => 'Rita',
            'last_name' => 'Risky',
            'email' => 'rita.risky@example.com',
        ],
        // Affordable, but credit score 640: referred, low credit risk.
        'fair credit' => [
            'first_name' => 'Fiona',
            'last_name' => 'Fair',
            'email' => 'fiona.fair@example.com',
        ],
        // Affordable, but the bureau has no file on them: referred, medium credit risk.
        'no credit file' => [
            'first_name' => 'Nina',
            'last_name' => 'Newcomer',
            'email' => 'nina.newcomer@example.com',
        ],
        // A 40,000 loan to consolidate debts, which takes 35% of income with the rent and leaves 40%:
        // scores 85, low risk. Credit score 650: referred, high credit risk.
        'large loan' => [
            'first_name' => 'Lars',
            'last_name' => 'Large',
            'email' => 'lars.large@example.com',
            'loan_amount' => 40000,
            'loan_purpose' => 'debt_consolidation',
        ],
        // An 80,000 business loan on a 15,000 income, which takes 25% of income with the rent and
        // leaves 55%: scores 100, low risk. No credit file: referred, highest credit risk.
        'business loan' => [
            'first_name' => 'Bianca',
            'last_name' => 'Business',
            'email' => 'bianca.business@example.com',
            'monthly_income' => 15000,
            'rent_or_mortgage' => 2000,
            'living_expenses' => 3000,
            'loan_amount' => 80000,
            'loan_purpose' => 'business',
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
