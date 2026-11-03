<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FormDraft;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerCaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_shows_the_whole_case_to_a_user_who_captures_customers(): void
    {
        $user = User::factory()->create(['name' => 'Casey Capturer']);
        $this->actingAs($user);
        $customer = $this->customerWithCase();
        FormDraft::factory()->for($user)->editing($customer, ['city' => 'Paris'])->create();

        $this->get("/customers/{$customer->id}/case")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('customers/Case')
                ->where('customer.first_name', 'Ada')
                ->where('customer.monthly_income', '6000.00')
                ->where('customer.loan_amount', '10000.00')
                ->where('showsFinances', true)
                ->has('runs', 2)
                ->where('runs.0.workflow', 'financial-scoring')
                ->where('runs.0.status_label', 'Referred: awaiting credit review')
                ->where('runs.0.details.credit_score', 640)
                ->where('runs.0.review_url', null)
                ->where('runs.1.workflow', 'customer-verification')
                ->where('runs.1.details.matches', ['Phone number +1 555 010 0666 is on the Demo sanctions list'])
                ->where('runs.1.history.0.label', 'Waiting for checks')
                ->where('timeline', fn ($timeline) => collect($timeline)->pluck('event')->all() === ['scored', 'flagged', 'created'])
                ->where('timeline.2.user.name', 'Casey Capturer')
                ->where('timeline.2.new_values.monthly_income', '6000.00')
                ->where('editUrl', route('customers.edit', $customer))
                ->where('hasDraft', true)
            );
    }

    public function test_a_compliance_officer_sees_the_case_without_the_finances_loan_or_scores(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $customer = $this->customerWithCase();
        $verification = $customer->verification;

        $this->get("/customers/{$customer->id}/case")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('showsFinances', false)
                ->where('customer.first_name', 'Ada')
                ->missing('customer.monthly_income')
                ->missing('customer.loan_amount')
                ->missing('customer.loan_purpose')
                ->where('runs.0.workflow', 'financial-scoring')
                ->where('runs.0.details', [])
                ->where('runs.1.review_url', route('compliance.reviews.show', $verification))
                ->where('timeline', fn ($timeline) => collect($timeline)->pluck('event')->all() === ['flagged', 'created'])
                ->missing('timeline.1.new_values.monthly_income')
                ->where('timeline.1.new_values.first_name', 'Ada')
                ->where('editUrl', null)
            );
    }

    public function test_a_credit_officer_sees_the_finances_and_a_link_to_a_review_waiting_for_them(): void
    {
        $this->actingAs(User::factory()->creditOfficer('low')->create());
        $customer = $this->customerWithCase();

        $this->get("/customers/{$customer->id}/case")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('showsFinances', true)
                ->where('customer.monthly_income', '6000.00')
                ->where('runs.0.review_url', route('credit.reviews.show', $customer->scoring))
                ->where('runs.1.review_url', null)
                ->where('editUrl', null)
            );
    }

    public function test_guests_are_sent_to_sign_in_and_an_unknown_customer_is_not_found(): void
    {
        $this->get('/customers/1/case')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/customers/999/case')->assertNotFound();
    }

    /**
     * A customer captured by the signed-in user, flagged at the contact check and referred for a credit review.
     */
    private function customerWithCase(): Customer
    {
        $customer = Customer::factory()->create([
            'first_name' => 'Ada',
            'phone' => '+1 555 010 0666',
            'monthly_income' => 6000,
            'loan_amount' => 10000,
        ]);
        $customer->auditLogs()->create([
            'user_id' => auth()->id(),
            'event' => 'created',
            'new_values' => ['first_name' => 'Ada', 'monthly_income' => '6000.00', 'loan_amount' => '10000.00'],
        ]);

        $customer->workflows()->create([
            'workflow' => WorkflowInstance::CUSTOMER_VERIFICATION,
            'status' => 'contact_review',
            'result' => ['matches' => ['Phone number +1 555 010 0666 is on the Demo sanctions list']],
        ]);
        $customer->auditLogs()->create(['event' => 'flagged', 'new_values' => ['failed_step' => 'contact']]);

        $customer->workflows()->create([
            'workflow' => WorkflowInstance::FINANCIAL_SCORING,
            'status' => 'credit_review',
            'result' => ['score' => 100, 'risk_band' => 'low', 'credit_score' => 640, 'credit_risk_tier' => 'low', 'reviews' => []],
        ]);
        $customer->auditLogs()->create(['event' => 'scored', 'new_values' => ['score' => 100]]);

        return $customer;
    }
}
