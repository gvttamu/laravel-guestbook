<?php

namespace Tests\Feature;

use App\Jobs\RunVerificationChecks;
use App\Jobs\ScoreCustomerFinancials;
use App\Models\Customer;
use App\Models\FormDraft;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_index_lists_customers_newest_first_with_their_latest_verification_and_scoring(): void
    {
        $this->actingAs(User::factory()->create());
        Customer::factory()->create(['first_name' => 'Ada']);
        $this->travel(1)->minutes();
        $grace = Customer::factory()->create(['first_name' => 'Grace']);
        $grace->workflows()->create(['workflow' => WorkflowInstance::CUSTOMER_VERIFICATION, 'status' => 'address_check']);
        $grace->workflows()->create(['workflow' => WorkflowInstance::FINANCIAL_SCORING, 'status' => 'superseded']);
        $grace->workflows()->create(['workflow' => WorkflowInstance::FINANCIAL_SCORING, 'status' => 'scored', 'result' => ['score' => 85, 'risk_band' => 'low']]);

        $this->get('/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('customers/Index')
                ->has('customers', 2)
                ->where('customers.0.first_name', 'Grace')
                ->where('customers.0.workflow.status', 'address_check')
                ->where('customers.0.scoring.status', 'scored')
                ->where('customers.0.scoring.result.score', 85)
                ->where('customers.1.first_name', 'Ada')
                ->where('customers.1.workflow', null)
                ->where('customers.1.scoring', null)
                ->where('hasDraft', false)
                ->where('auditHistory', null)
            );
    }

    public function test_index_shows_the_selected_customers_audit_history_newest_first(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['first_name' => 'Ada']);
        $customer->auditLogs()->create(['event' => 'created', 'new_values' => ['city' => 'London']]);
        $this->travel(1)->minutes();
        $editor = User::factory()->create(['name' => 'Grace Hopper']);
        $customer->auditLogs()->create([
            'event' => 'updated',
            'user_id' => $editor->id,
            'old_values' => ['city' => 'London'],
            'new_values' => ['city' => 'Paris'],
        ]);
        Customer::factory()->create()->auditLogs()->create(['event' => 'created', 'new_values' => ['city' => 'Elsewhere']]);

        $this->get("/customers?customer={$customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auditHistory.customer.first_name', 'Ada')
                ->has('auditHistory.entries', 2)
                ->where('auditHistory.entries.0.event', 'updated')
                ->where('auditHistory.entries.0.user.name', 'Grace Hopper')
                ->where('auditHistory.entries.0.old_values', ['city' => 'London'])
                ->where('auditHistory.entries.0.new_values', ['city' => 'Paris'])
                ->where('auditHistory.entries.1.event', 'created')
                ->where('auditHistory.entries.1.user', null)
            );
    }

    public function test_edit_starts_a_draft_from_the_customers_saved_details(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $customer = Customer::factory()->create(['first_name' => 'Ada', 'date_of_birth' => '1990-12-10']);

        $response = $this->get("/customers/{$customer->id}/edit");

        $draft = FormDraft::sole();
        $response->assertRedirect("/customer-drafts/{$draft->id}/basic");
        $this->assertTrue($draft->user->is($user));
        $this->assertTrue($draft->customer->is($customer));
        $this->assertSame(1, $draft->customer_version);
        $this->assertSame('Ada', $draft->payload['first_name']);
        $this->assertSame('1990-12-10', $draft->payload['date_of_birth']);
        $this->assertSame($draft->payload, $draft->original_payload);

        $this->get("/customer-drafts/{$draft->id}/basic")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('customers/CustomerForm')
                ->where('draft.customer_version', 1)
                ->where('customer.id', $customer->id)
                ->where('customer.version', 1)
                ->where('customer.first_name', 'Ada')
            );
    }

    public function test_edit_resumes_your_draft_of_changes_at_its_last_step(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $customer = Customer::factory()->create();
        $draft = FormDraft::factory()->for($user)->editing($customer, ['city' => 'Paris'])->create(['current_step' => 'financials']);
        FormDraft::factory()->editing($customer, ['city' => 'Rome'])->create();

        $this->get("/customers/{$customer->id}/edit")
            ->assertRedirect("/customer-drafts/{$draft->id}/financials");

        $this->assertSame(2, FormDraft::count());
    }

    public function test_update_saves_changes_audits_only_the_changed_fields_and_clears_the_draft(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['city' => 'London', 'date_of_birth' => '1990-12-10']);

        $this->saveEdits($customer, ['city' => 'Paris', 'date_of_birth' => '1991-01-01'])
            ->assertRedirect('/inbox')
            ->assertInertiaFlash('customerSaved.outcome', 'updated')
            ->assertSessionHasNoErrors();

        $this->assertSame(0, FormDraft::count());

        $customer->refresh();
        $this->assertSame('Paris', $customer->city);
        $this->assertSame('1991-01-01', $customer->date_of_birth->toDateString());
        $this->assertSame(2, $customer->version);

        $audit = $customer->auditLogs()->sole();
        $this->assertSame('updated', $audit->event);
        $this->assertEquals(['city' => 'London', 'date_of_birth' => '1990-12-10'], $audit->old_values);
        $this->assertEquals(['city' => 'Paris', 'date_of_birth' => '1991-01-01'], $audit->new_values);
        $this->assertSame('127.0.0.1', $audit->ip_address);
    }

    public function test_changing_screened_details_supersedes_the_unfinished_run_and_starts_a_new_check(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake([RunVerificationChecks::class, ScoreCustomerFinancials::class]);
        $customer = Customer::factory()->create();
        $verified = $this->verificationRun($customer, 'verified');
        $this->travel(1)->minutes();
        $flagged = $this->verificationRun($customer, 'contact_review');

        $this->saveEdits($customer, ['phone' => '+44 20 7946 0000'])
            ->assertSessionHasNoErrors();

        $this->assertSame('verified', $verified->refresh()->status);
        $this->assertSame('superseded', $flagged->refresh()->status);
        $newRun = $customer->workflow()->sole();
        $this->assertSame('pending', $newRun->status);
        $this->assertSame(3, $customer->workflows()->count());
        Queue::assertPushed(RunVerificationChecks::class, 1);
        Queue::assertPushed(RunVerificationChecks::class, fn (RunVerificationChecks $job) => $job->workflowInstance->is($newRun));
    }

    public function test_changing_financials_supersedes_the_unfinished_scoring_run_and_scores_again(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake([RunVerificationChecks::class, ScoreCustomerFinancials::class]);
        $customer = Customer::factory()->create(['car_payments' => 250]);
        $verification = $this->verificationRun($customer, 'contact_review');
        $scoring = $customer->workflows()->create(['workflow' => WorkflowInstance::FINANCIAL_SCORING, 'status' => 'pending']);

        $this->saveEdits($customer, ['car_payments' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull($customer->refresh()->car_payments);
        $this->assertSame('contact_review', $verification->refresh()->status);
        $this->assertSame('superseded', $scoring->refresh()->status);
        $newRun = $customer->scoring()->sole();
        $this->assertSame('pending', $newRun->status);
        Queue::assertPushed(ScoreCustomerFinancials::class, 1);
        Queue::assertPushed(ScoreCustomerFinancials::class, fn (ScoreCustomerFinancials $job) => $job->workflowInstance->is($newRun));
        Queue::assertNotPushed(RunVerificationChecks::class);
    }

    public function test_an_equal_amount_in_another_format_is_not_a_change(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake([RunVerificationChecks::class, ScoreCustomerFinancials::class]);
        $customer = Customer::factory()->create(['monthly_income' => 4200]);

        $this->saveEdits($customer, ['monthly_income' => 4200])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $customer->auditLogs()->count());
        $this->assertSame(0, $customer->workflows()->count());
    }

    public function test_changing_the_loan_amount_or_term_scores_again_without_rescreening(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake([RunVerificationChecks::class, ScoreCustomerFinancials::class]);
        $customer = Customer::factory()->create(['loan_amount' => 10000, 'loan_term_months' => 24]);

        $this->saveEdits($customer, ['loan_term_months' => 36])
            ->assertSessionHasNoErrors();

        $this->assertEquals(['loan_term_months' => 36], $customer->auditLogs()->sole()->new_values);
        $this->assertSame([WorkflowInstance::FINANCIAL_SCORING], $customer->workflows()->pluck('workflow')->all());
        Queue::assertPushed(ScoreCustomerFinancials::class, fn (ScoreCustomerFinancials $job) => $job->workflowInstance->is($customer->scoring));
    }

    public function test_changing_only_the_loan_purpose_is_audited_without_rerunning_any_workflow(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake([RunVerificationChecks::class, ScoreCustomerFinancials::class]);
        $customer = Customer::factory()->create(['loan_purpose' => 'car']);

        $this->saveEdits($customer, ['loan_purpose' => 'education'])
            ->assertSessionHasNoErrors();

        $this->assertEquals(['loan_purpose' => 'education'], $customer->auditLogs()->sole()->new_values);
        $this->assertSame(0, $customer->workflows()->count());
        Queue::assertNothingPushed();
    }

    public function test_the_loan_needs_a_known_purpose_a_term_in_range_and_details_for_other(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();

        $this->saveEdits($customer, ['loan_purpose' => 'holiday', 'loan_term_months' => 361])
            ->assertSessionHasErrors(['loan_purpose' => 'The selected loan purpose is invalid.', 'loan_term_months']);

        $this->saveEdits($customer, ['loan_purpose' => 'other', 'loan_purpose_details' => null])
            ->assertSessionHasErrors(['loan_purpose_details' => 'The loan purpose details field is required when loan purpose is other.']);

        $this->assertSame(1, $customer->refresh()->version);
    }

    public function test_changing_details_the_check_does_not_screen_keeps_the_current_run(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake([RunVerificationChecks::class, ScoreCustomerFinancials::class]);
        $customer = Customer::factory()->create(['first_name' => 'Ada']);
        $flagged = $this->verificationRun($customer, 'contact_review');

        $this->saveEdits($customer, ['first_name' => 'Augusta', 'city' => 'Paris'])
            ->assertSessionHasNoErrors();

        $this->assertSame('contact_review', $flagged->refresh()->status);
        $this->assertSame(1, $customer->workflows()->count());
        Queue::assertNothingPushed();
    }

    public function test_update_without_changes_records_no_audit_entry(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['date_of_birth' => '1990-12-10', 'address_line2' => null]);

        $this->saveEdits($customer, ['address_line2' => null])
            ->assertRedirect('/inbox')
            ->assertInertiaFlash('customerSaved.outcome', 'unchanged');

        $this->assertSame(0, $customer->auditLogs()->count());
    }

    public function test_update_rejects_a_draft_started_before_someone_else_saved_and_keeps_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $customer = Customer::factory()->create(['city' => 'London']);
        $staleDraft = FormDraft::factory()->for($user)->editing($customer, ['phone' => '+1 555 000 0000'])->create();
        $this->saveEdits($customer, ['city' => 'Paris']);

        $this->from("/customer-drafts/{$staleDraft->id}/basic")
            ->post("/customer-drafts/{$staleDraft->id}/submit")
            ->assertRedirect("/customer-drafts/{$staleDraft->id}/basic")
            ->assertSessionHasErrors(['version' => 'Someone else changed this customer after you started editing, so your changes weren’t saved.']);

        $customer->refresh();
        $this->assertSame('Paris', $customer->city);
        $this->assertNotSame('+1 555 000 0000', $customer->phone);
        $this->assertSame(2, $customer->version);
        $this->assertSame(1, $customer->auditLogs()->count());
        $this->assertModelExists($staleDraft);
    }

    public function test_update_rejects_invalid_details_and_leaves_the_customer_and_draft_unchanged(): void
    {
        $this->actingAs(User::factory()->create());
        Customer::factory()->create(['email' => 'taken@example.com']);
        $customer = Customer::factory()->create(['first_name' => 'Ada']);

        $this->saveEdits($customer, ['first_name' => null, 'email' => 'taken@example.com'])
            ->assertSessionHasErrors([
                'first_name' => 'The first name field is required.',
                'email' => 'The email has already been taken.',
            ]);

        $this->assertSame('Ada', $customer->refresh()->first_name);
        $this->assertSame(0, $customer->auditLogs()->count());
        $this->assertSame(1, FormDraft::count());
    }

    public function test_index_has_no_audit_history_for_an_unknown_customer(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/customers?customer=999')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auditHistory', null));
    }

    public function test_index_offers_to_continue_your_unfinished_draft(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        FormDraft::factory()->create();

        $this->get('/customers')->assertInertia(fn (Assert $page) => $page->where('hasDraft', false));

        FormDraft::factory()->for($user)->create();

        $this->get('/customers')->assertInertia(fn (Assert $page) => $page->where('hasDraft', true));
    }

    public function test_index_lists_the_customers_you_have_unsaved_changes_to(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $customer = Customer::factory()->create();
        FormDraft::factory()->for($user)->editing($customer)->create();
        FormDraft::factory()->editing(Customer::factory()->create())->create();

        $this->get('/customers')
            ->assertInertia(fn (Assert $page) => $page->where('customerIdsWithDrafts', [$customer->id]));
    }

    private function verificationRun(Customer $customer, string $status): WorkflowInstance
    {
        return $customer->workflows()->create([
            'workflow' => WorkflowInstance::CUSTOMER_VERIFICATION,
            'status' => $status,
        ]);
    }

    /**
     * As the signed-in user, start a draft of changes from the customer as currently saved,
     * make the given changes, and save it.
     *
     * @param  array<string, string|int|null>  $changes
     */
    private function saveEdits(Customer $customer, array $changes): TestResponse
    {
        $draft = FormDraft::factory()->for(auth()->user())->editing($customer, $changes)->create();

        return $this->post("/customer-drafts/{$draft->id}/submit");
    }
}
