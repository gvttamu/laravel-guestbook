<?php

namespace Tests\Feature;

use App\Jobs\StartWorkflowRun;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\FormDraft;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerDraftTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_create_starts_a_draft_on_the_first_step(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $response = $this->get('/customers/create');

        $draft = FormDraft::sole();
        $response->assertRedirect("/customer-drafts/{$draft->id}/basic");
        $this->assertTrue($draft->user->is($user));
        $this->assertSame([], $draft->payload);
        $this->assertSame(1, $draft->version);
    }

    public function test_create_resumes_your_draft_at_its_last_step(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $draft = FormDraft::factory()->for($user)->create(['current_step' => 'details']);
        FormDraft::factory()->editing(Customer::factory()->create())->for($user)->create();
        FormDraft::factory()->create();

        $this->get('/customers/create')
            ->assertRedirect("/customer-drafts/{$draft->id}/details");

        $this->assertSame(3, FormDraft::count());
    }

    public function test_edit_shows_the_saved_draft(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $draft = FormDraft::factory()->for($user)->create(['payload' => ['first_name' => 'Ada'], 'version' => 4]);

        $this->get("/customer-drafts/{$draft->id}/details")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('customers/CustomerForm')
                ->where('step', 'details')
                ->where('draft.id', $draft->id)
                ->where('draft.payload.first_name', 'Ada')
                ->where('draft.version', 4)
                ->where('customer', null)
            );
    }

    public function test_another_users_draft_is_not_found(): void
    {
        $this->actingAs(User::factory()->create());
        $draft = FormDraft::factory()->completeCustomer()->create();

        $this->get("/customer-drafts/{$draft->id}/basic")->assertNotFound();
        $this->patchJson("/customer-drafts/{$draft->id}", ['version' => 1, 'current_step' => 'basic'])->assertNotFound();
        $this->post("/customer-drafts/{$draft->id}/submit")->assertNotFound();
        $this->delete("/customer-drafts/{$draft->id}")->assertNotFound();

        $this->assertSame(0, Customer::count());
        $this->assertModelExists($draft);
    }

    public function test_discarding_a_new_customer_draft_deletes_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $draft = FormDraft::factory()->for($user)->completeCustomer()->create();

        $this->delete("/customer-drafts/{$draft->id}")
            ->assertRedirect('/customers');

        $this->assertModelMissing($draft);
        $this->assertSame(0, Customer::count());
    }

    public function test_discarding_changes_leaves_the_customer_unchanged(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $customer = Customer::factory()->create(['city' => 'London']);
        $draft = FormDraft::factory()->for($user)->editing($customer, ['city' => 'Paris'])->create();

        $this->delete("/customer-drafts/{$draft->id}")
            ->assertRedirect("/customers?customer={$customer->id}");

        $this->assertModelMissing($draft);
        $this->assertSame('London', $customer->refresh()->city);
        $this->assertSame(0, $customer->auditLogs()->count());
    }

    public function test_discarding_with_reopen_starts_editing_again_from_the_latest_details(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $customer = Customer::factory()->create(['city' => 'London']);
        $staleDraft = FormDraft::factory()->for($user)->editing($customer, ['phone' => '+1 555 000 0000'])->create();
        $customer->forceFill(['city' => 'Paris', 'version' => 2])->save();

        $this->delete("/customer-drafts/{$staleDraft->id}", ['reopen' => true])
            ->assertRedirect("/customers/{$customer->id}/edit");

        $this->get("/customers/{$customer->id}/edit");

        $freshDraft = FormDraft::sole();
        $this->assertFalse($freshDraft->is($staleDraft));
        $this->assertSame(2, $freshDraft->customer_version);
        $this->assertSame('Paris', $freshDraft->payload['city']);
    }

    public function test_autosave_stores_partial_details_and_bumps_the_version(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $draft = FormDraft::factory()->for($user)->create(['version' => 3]);

        $this->patchJson("/customer-drafts/{$draft->id}", [
            'first_name' => 'Ada',
            'email' => 'ada@exa',
            'current_step' => 'basic',
            'version' => 3,
        ])
            ->assertOk()
            ->assertExactJson(['version' => 4]);

        $draft->refresh();
        $this->assertSame(['first_name' => 'Ada', 'email' => 'ada@exa'], $draft->payload);
        $this->assertSame(4, $draft->version);
        $this->assertSame(0, Customer::count());
    }

    public function test_autosave_rejects_a_save_based_on_an_old_version(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $draft = FormDraft::factory()->for($user)->create(['payload' => ['first_name' => 'Newer'], 'version' => 5]);

        $this->patchJson("/customer-drafts/{$draft->id}", [
            'first_name' => 'Older',
            'current_step' => 'basic',
            'version' => 4,
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'This draft was changed in another tab or window. Reload to continue.');

        $draft->refresh();
        $this->assertSame(['first_name' => 'Newer'], $draft->payload);
        $this->assertSame(5, $draft->version);
    }

    public function test_autosave_rejects_values_that_are_too_long_to_store(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $draft = FormDraft::factory()->for($user)->create();

        $this->patchJson("/customer-drafts/{$draft->id}", [
            'first_name' => str_repeat('a', 101),
            'current_step' => 'basic',
            'version' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name' => 'The first name field must not be greater than 100 characters.']);

        $this->assertSame(1, $draft->refresh()->version);
    }

    public function test_submitting_creates_the_customer_audits_it_starts_its_workflows_and_clears_the_draft(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        config(['services.flowable.url' => 'http://flowable.test/process-api']);
        Queue::fake([StartWorkflowRun::class]);
        $draft = FormDraft::factory()->for($user)->completeCustomer()->create();

        $response = $this->post("/customer-drafts/{$draft->id}/submit")
            ->assertRedirect('/inbox');

        $customer = Customer::sole();
        $response->assertInertiaFlash('customerSaved', ['id' => $customer->id, 'name' => 'Ada Lovelace', 'outcome' => 'created']);
        $this->assertSame('ada@example.com', $customer->email);
        $this->assertSame('1990-12-10', $customer->date_of_birth->toDateString());
        $this->assertSame('1350.50', $customer->rent_or_mortgage);
        $this->assertNull($customer->other_debt_payments);
        $this->assertSame('15000.00', $customer->loan_amount);
        $this->assertSame(48, $customer->loan_term_months);
        $this->assertSame('home_improvement', $customer->loan_purpose);

        $audit = AuditLog::sole();
        $this->assertTrue($audit->auditable->is($customer));
        $this->assertSame('created', $audit->event);
        $this->assertSame('Lovelace', $audit->new_values['last_name']);
        $this->assertEquals(4200, $audit->new_values['monthly_income']);

        $this->assertSame(
            ['customer-verification' => 'pending', 'financial-scoring' => 'pending'],
            $customer->workflows()->pluck('status', 'workflow')->all(),
        );
        Queue::assertPushed(StartWorkflowRun::class, 2);
        Queue::assertPushed(StartWorkflowRun::class, fn (StartWorkflowRun $job) => $job->workflowInstance->is($customer->workflow));
        Queue::assertPushed(StartWorkflowRun::class, fn (StartWorkflowRun $job) => $job->workflowInstance->is($customer->scoring));

        $this->assertModelMissing($draft);
    }

    public function test_submitting_without_flowable_configured_leaves_the_workflows_pending(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        config(['services.flowable.url' => null]);
        Queue::fake([StartWorkflowRun::class]);
        $draft = FormDraft::factory()->for($user)->completeCustomer()->create();

        $this->post("/customer-drafts/{$draft->id}/submit")
            ->assertRedirect('/inbox');

        $this->assertSame(['pending'], WorkflowInstance::distinct()->pluck('status')->all());
        Queue::assertNotPushed(StartWorkflowRun::class);
    }

    public function test_submitting_an_incomplete_draft_keeps_it_and_reports_missing_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $draft = FormDraft::factory()->for($user)->create(['payload' => ['first_name' => 'Ada']]);

        $this->from("/customer-drafts/{$draft->id}/details")
            ->post("/customer-drafts/{$draft->id}/submit")
            ->assertRedirect("/customer-drafts/{$draft->id}/details")
            ->assertSessionHasErrors([
                'last_name' => 'The last name field is required.',
                'email' => 'The email field is required.',
                'city' => 'The city field is required.',
                'monthly_income' => 'The monthly income field is required.',
                'loan_amount' => 'The loan amount field is required.',
                'loan_purpose' => 'The loan purpose field is required.',
            ])
            ->assertSessionDoesntHaveErrors(['first_name', 'date_of_birth', 'address_line2']);

        $this->assertModelExists($draft);
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, AuditLog::count());
        $this->assertSame(0, WorkflowInstance::count());
    }

    public function test_submitting_with_an_email_that_is_already_taken_fails(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Customer::factory()->create(['email' => 'ada@example.com']);
        $draft = FormDraft::factory()->for($user)->completeCustomer()->create();

        $this->post("/customer-drafts/{$draft->id}/submit")
            ->assertSessionHasErrors(['email' => 'The email has already been taken.']);

        $this->assertModelExists($draft);
        $this->assertSame(1, Customer::count());
    }

    public function test_drafts_untouched_for_30_days_are_pruned(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $stale = FormDraft::factory()->for($user)->create(['updated_at' => now()->subDays(31)]);
        $recent = FormDraft::factory()->for($user)->create(['updated_at' => now()->subDays(29)]);

        $this->artisan('model:prune', ['--model' => [FormDraft::class]])->assertSuccessful();

        $this->assertModelMissing($stale);
        $this->assertModelExists($recent);
    }
}
