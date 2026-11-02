<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FormDraft;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/inbox')->assertRedirect('/login');
    }

    public function test_a_compliance_officer_sees_open_reviews_but_no_drafts(): void
    {
        $officer = User::factory()->complianceOfficer()->create();
        $this->actingAs($officer);
        $flagged = $this->flaggedRun(['first_name' => 'Pete', 'last_name' => 'Smith']);
        $flagged->subject->workflows()->create(['workflow' => WorkflowInstance::CUSTOMER_VERIFICATION, 'status' => 'verified']);
        FormDraft::factory()->for($officer)->create();

        $this->get('/inbox')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox')
                ->has('items', 1)
                ->where('items.0.type', 'sanctions_review')
                ->where('items.0.customer.first_name', 'Pete')
                ->where('items.0.failed_step', 'contact')
                ->where('items.0.matches', ['Phone number +1 555 010 0666 is on the Demo sanctions list'])
                ->where('items.0.since', '2026-10-28T21:00:00+00:00')
                ->where('items.0.url', route('compliance.reviews.show', $flagged))
                ->where('inboxCount', 1)
            );
    }

    public function test_a_compliance_officer_sees_only_reviews_that_are_unassigned_or_assigned_to_them(): void
    {
        $officer = User::factory()->complianceOfficer()->create();
        $colleague = User::factory()->complianceOfficer()->create();
        $this->actingAs($officer);
        $this->flaggedRun(['first_name' => 'Una']);
        $mine = $this->flaggedRun(['first_name' => 'Mia']);
        $mine->assignedTo()->associate($officer)->save();
        $this->flaggedRun(['first_name' => 'Cole'])->assignedTo()->associate($colleague)->save();

        $this->get('/inbox')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 2)
                ->where('items', fn ($items) => collect($items)->pluck('customer.first_name')->sort()->values()->all() === ['Mia', 'Una'])
                ->where('inboxCount', 2)
            );
    }

    public function test_a_credit_officer_sees_referred_loan_applications_but_no_sanctions_reviews_or_drafts(): void
    {
        $officer = User::factory()->creditOfficer()->create();
        $this->actingAs($officer);
        $this->flaggedRun([]);
        FormDraft::factory()->for($officer)->create();
        $referred = Customer::factory()->create(['first_name' => 'Fiona', 'loan_amount' => 10000])->workflows()->create([
            'workflow' => WorkflowInstance::FINANCIAL_SCORING,
            'status' => 'credit_review',
            'result' => ['score' => 100, 'credit_score' => 640, 'credit_risk_tier' => 'low', 'engine_reasons' => ['Credit score 640 is below 700, the score for automatic approval'], 'referred_at' => '2026-11-01T09:00:00+00:00'],
        ]);
        Customer::factory()->create()->workflows()->create(['workflow' => WorkflowInstance::FINANCIAL_SCORING, 'status' => 'approved']);

        $this->get('/inbox')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 1)
                ->where('items.0.type', 'credit_review')
                ->where('items.0.customer.first_name', 'Fiona')
                ->where('items.0.loan_amount', '10000.00')
                ->where('items.0.score', 100)
                ->where('items.0.credit_score', 640)
                ->where('items.0.reasons', ['Credit score 640 is below 700, the score for automatic approval'])
                ->where('items.0.since', '2026-11-01T09:00:00+00:00')
                ->where('items.0.url', route('credit.reviews.show', $referred))
                ->where('inboxCount', 1)
            );

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('inboxCount', 1));
    }

    public function test_users_who_capture_customers_see_their_own_drafts_oldest_first(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->flaggedRun([]);
        $this->travelTo('2026-10-28 20:00:00');
        $newCustomer = FormDraft::factory()->for($user)->create(['payload' => ['first_name' => 'Ada'], 'current_step' => 'details']);
        $this->travelTo('2026-10-29 09:00:00');
        $edited = Customer::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);
        $changes = FormDraft::factory()->for($user)->editing($edited, ['city' => 'Paris'])->create(['current_step' => 'financials']);
        FormDraft::factory()->create();

        $this->get('/inbox')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 2)
                ->where('items.0.type', 'new_customer')
                ->where('items.0.customer.first_name', 'Ada')
                ->where('items.0.customer.last_name', null)
                ->where('items.0.step', 'details')
                ->where('items.0.url', route('customer-drafts.edit', ['customerDraft' => $newCustomer, 'step' => 'details']))
                ->where('items.1.type', 'customer_changes')
                ->where('items.1.customer.first_name', 'Grace')
                ->where('items.1.is_out_of_date', false)
                ->where('items.1.url', route('customer-drafts.edit', ['customerDraft' => $changes, 'step' => 'financials']))
            );
    }

    public function test_every_page_shows_how_many_items_are_in_the_inbox(): void
    {
        $officer = User::factory()->complianceOfficer()->create();
        $user = User::factory()->create();
        FormDraft::factory()->for($officer)->count(2)->create();
        FormDraft::factory()->for($user)->create();
        $this->flaggedRun([]);
        $this->flaggedRun([])->update(['status' => 'address_check']);

        $this->actingAs($officer)->get('/')->assertInertia(fn (Assert $page) => $page->where('inboxCount', 1));
        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page->where('inboxCount', 1));
    }

    public function test_changes_to_a_customer_someone_else_has_since_saved_are_marked_out_of_date(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $customer = Customer::factory()->create();
        FormDraft::factory()->for($user)->editing($customer, ['city' => 'Paris'])->create();
        $customer->forceFill(['version' => 2])->save();

        $this->get('/inbox')->assertInertia(fn (Assert $page) => $page->where('items.0.is_out_of_date', true));
    }

    /**
     * @param  array<string, mixed>  $customerAttributes
     */
    private function flaggedRun(array $customerAttributes): WorkflowInstance
    {
        return Customer::factory()->create($customerAttributes)->workflows()->create([
            'workflow' => WorkflowInstance::CUSTOMER_VERIFICATION,
            'status' => 'contact_review',
            'result' => [
                'status' => 'flagged',
                'failed_step' => 'contact',
                'matches' => ['Phone number +1 555 010 0666 is on the Demo sanctions list'],
                'flagged_at' => '2026-10-28T21:00:00+00:00',
            ],
        ]);
    }
}
