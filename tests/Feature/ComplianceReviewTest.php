<?php

namespace Tests\Feature;

use App\Jobs\NotifyOfficerOfScreeningReview;
use App\Jobs\RunVerificationChecks;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ComplianceReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PHONE_MATCH = 'Phone number +1 555 010 0666 is on the Demo sanctions list';

    private const ADDRESS_MATCH = 'Address 666 Shadow Lane, 66666, United States is on the Demo sanctions list';

    public function test_lists_runs_awaiting_review_longest_waiting_first_with_the_flagged_customer(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $address = $this->flaggedRun('address', ['first_name' => 'Grace'], flaggedAt: '2026-10-29T09:00:00+00:00');
        $contact = $this->flaggedRun('contact', ['first_name' => 'Ada', 'email' => 'ada@example.com'], flaggedAt: '2026-10-28T21:00:00+00:00');
        Customer::factory()->create()->workflows()->create(['workflow' => WorkflowInstance::CUSTOMER_VERIFICATION, 'status' => 'verified']);

        $this->get('/compliance/reviews')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('compliance/Reviews')
                ->has('reviews', 2)
                ->where('reviews.0.id', $contact->id)
                ->where('reviews.0.failed_step', 'contact')
                ->where('reviews.0.matches', [self::PHONE_MATCH])
                ->where('reviews.0.flagged_at', '2026-10-28T21:00:00+00:00')
                ->where('reviews.0.customer.email', 'ada@example.com')
                ->where('reviews.1.id', $address->id)
                ->where('reviews.1.failed_step', 'address')
            );
    }

    public function test_the_list_leaves_out_reviews_assigned_to_other_officers(): void
    {
        $officer = User::factory()->complianceOfficer()->create();
        $this->actingAs($officer);
        $mine = $this->flaggedRun('contact', flaggedAt: '2026-10-28T09:00:00+00:00');
        $mine->assignedTo()->associate($officer)->save();
        $unassigned = $this->flaggedRun('address', flaggedAt: '2026-10-29T09:00:00+00:00');
        $this->flaggedRun('contact')->assignedTo()->associate(User::factory()->complianceOfficer()->create())->save();

        $this->get('/compliance/reviews')->assertInertia(fn (Assert $page) => $page
            ->has('reviews', 2)
            ->where('reviews.0.id', $mine->id)
            ->where('reviews.1.id', $unassigned->id)
        );
    }

    public function test_shows_a_review_with_the_customers_details_but_not_their_finances(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $run = $this->flaggedRun('contact', ['first_name' => 'Ada', 'city' => 'London']);

        $this->get("/compliance/reviews/{$run->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('compliance/Review')
                ->where('review.id', $run->id)
                ->where('review.failed_step', 'contact')
                ->where('review.customer.first_name', 'Ada')
                ->where('review.customer.city', 'London')
                ->missing('review.customer.monthly_income')
                ->missing('review.customer.loan_amount')
                ->where('review.earlier_reviews', [])
            );
    }

    public function test_a_decided_review_shows_a_summary_of_the_decision_instead_of_the_form(): void
    {
        $officer = User::factory()->complianceOfficer()->create(['name' => 'Olive Officer']);
        $this->actingAs($officer);
        $run = $this->flaggedRun('contact', ['first_name' => 'Ada']);
        $this->post("/compliance/reviews/{$run->id}", ['step' => 'contact', 'decision' => 'rejected', 'notes' => 'Same person']);

        $this->get("/compliance/reviews/{$run->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('compliance/ReviewSummary')
                ->where('review.id', $run->id)
                ->where('review.status', 'rejected')
                ->where('review.status_label', 'Rejected')
                ->where('review.customer.first_name', 'Ada')
                ->missing('review.customer.phone')
                ->has('review.reviews', 1)
                ->where('review.reviews.0.step', 'contact')
                ->where('review.reviews.0.decision', 'rejected')
                ->where('review.reviews.0.notes', 'Same person')
                ->where('review.reviews.0.reviewed_by', 'Olive Officer')
                ->where('review.reviews.0.matches', [self::PHONE_MATCH])
            );
    }

    public function test_a_review_assigned_to_another_officer_shows_a_summary_naming_them_instead_of_the_form(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $run = $this->flaggedRun('contact', ['first_name' => 'Ada']);
        $run->assignedTo()->associate(User::factory()->complianceOfficer()->create(['name' => 'Oscar Overseer']))->save();

        $this->get("/compliance/reviews/{$run->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('compliance/ReviewSummary')
                ->where('review.assigned_to', 'Oscar Overseer')
                ->where('review.status', 'contact_review')
                ->where('review.customer.first_name', 'Ada')
                ->missing('review.customer.phone')
            );
    }

    public function test_opening_an_unassigned_review_assigns_it_to_the_officer_so_others_see_a_summary(): void
    {
        $olive = User::factory()->complianceOfficer()->create(['name' => 'Olive Officer']);
        $oscar = User::factory()->complianceOfficer()->create();
        $run = $this->flaggedRun('contact');

        $this->actingAs($olive)->get("/compliance/reviews/{$run->id}")->assertInertia(fn (Assert $page) => $page
            ->component('compliance/Review')
            ->where('review.assignee', ['id' => $olive->id, 'name' => 'Olive Officer'])
        );

        $this->assertTrue($run->refresh()->assignedTo->is($olive));
        $audit = $run->subject->auditLogs()->sole();
        $this->assertSame('assigned', $audit->event);
        $this->assertTrue($audit->user->is($olive));
        $this->assertSame(['review_assignee' => 'Olive Officer'], $audit->new_values);

        $this->actingAs($oscar)->get("/compliance/reviews/{$run->id}")->assertInertia(fn (Assert $page) => $page
            ->component('compliance/ReviewSummary')
            ->where('review.assigned_to', 'Olive Officer')
        );
        $this->get('/compliance/reviews')->assertInertia(fn (Assert $page) => $page->has('reviews', 0));
    }

    public function test_opening_a_completed_review_assigns_it_to_nobody(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $run = $this->flaggedRun('contact');
        $run->update(['status' => 'rejected']);

        $this->get("/compliance/reviews/{$run->id}")->assertInertia(fn (Assert $page) => $page->component('compliance/ReviewSummary'));

        $this->assertNull($run->refresh()->assigned_to_id);
        $this->assertSame(0, $run->subject->auditLogs()->count());
    }

    public function test_a_review_assigned_to_the_officer_viewing_it_shows_the_form(): void
    {
        $officer = User::factory()->complianceOfficer()->create();
        $this->actingAs($officer);
        $run = $this->flaggedRun('contact');
        $run->assignedTo()->associate($officer)->save();

        $this->get("/compliance/reviews/{$run->id}")->assertInertia(fn (Assert $page) => $page->component('compliance/Review'));
    }

    public function test_a_review_assigned_to_another_officer_cannot_be_decided_or_reassigned(): void
    {
        Queue::fake([NotifyOfficerOfScreeningReview::class]);
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $oscar = User::factory()->complianceOfficer()->create(['name' => 'Oscar Overseer']);
        $run = $this->flaggedRun('contact');
        $run->assignedTo()->associate($oscar)->save();

        $this->post("/compliance/reviews/{$run->id}", ['step' => 'contact', 'decision' => 'approved'])
            ->assertRedirect('/compliance/reviews')
            ->assertInertiaFlash('error', 'This review is assigned to Oscar Overseer, so your decision wasn’t recorded.');

        $this->put("/compliance/reviews/{$run->id}/assignee", ['step' => 'contact', 'assignee_id' => null])
            ->assertRedirect('/compliance/reviews')
            ->assertInertiaFlash('error', 'This review is assigned to Oscar Overseer, so it wasn’t reassigned.');

        $run->refresh();
        $this->assertSame('contact_review', $run->status);
        $this->assertTrue($run->assignedTo->is($oscar));
        $this->assertSame(0, $run->subject->auditLogs()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_review_superseded_before_anyone_decided_it_shows_a_summary_with_no_decisions(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $run = $this->flaggedRun('address');
        $run->update(['status' => 'superseded']);

        $this->get("/compliance/reviews/{$run->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('compliance/ReviewSummary')
                ->where('review.status', 'superseded')
                ->where('review.reviews', [])
            );
    }

    public function test_an_unknown_run_or_a_run_of_another_workflow_is_not_found(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $scoring = Customer::factory()->create()->workflows()->create(['workflow' => WorkflowInstance::FINANCIAL_SCORING, 'status' => 'scored']);

        $this->get("/compliance/reviews/{$scoring->id}")->assertNotFound();
        $this->get('/compliance/reviews/999')->assertNotFound();
    }

    public function test_approving_a_contact_match_records_the_review_and_queues_the_address_check(): void
    {
        $officer = User::factory()->complianceOfficer()->create();
        $this->actingAs($officer);
        Queue::fake([RunVerificationChecks::class]);
        $run = $this->flaggedRun('contact');

        $this->post("/compliance/reviews/{$run->id}", ['step' => 'contact', 'decision' => 'approved', 'notes' => 'Different person'])
            ->assertRedirect('/compliance/reviews');

        $run->refresh();
        $this->assertSame('address_check', $run->status);
        $this->assertSame('contact', $run->result['reviews'][0]['step']);
        $this->assertSame([self::PHONE_MATCH], $run->result['reviews'][0]['matches']);
        $this->assertSame('approved', $run->result['reviews'][0]['decision']);
        $this->assertSame('Different person', $run->result['reviews'][0]['notes']);
        $this->assertSame($officer->id, $run->result['reviews'][0]['reviewed_by']);
        Queue::assertPushed(RunVerificationChecks::class, fn (RunVerificationChecks $job) => $job->workflowInstance->is($run));

        $audit = $run->subject->auditLogs()->sole();
        $this->assertSame('approved', $audit->event);
        $this->assertTrue($audit->user->is($officer));
        $this->assertSame(['review_step' => 'contact', 'review_decision' => 'approved', 'review_notes' => 'Different person'], $audit->new_values);
    }

    public function test_an_approved_contact_match_with_a_clear_address_ends_approved(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $run = $this->flaggedRun('contact');

        $this->post("/compliance/reviews/{$run->id}", ['step' => 'contact', 'decision' => 'approved']);

        $this->assertSame('approved', $run->refresh()->status);
        $this->assertSame(['approved', 'approved'], $run->subject->auditLogs()->orderBy('id')->pluck('event')->all());
        $this->assertNull($run->subject->auditLogs()->orderByDesc('id')->first()->user_id);
    }

    public function test_an_approved_contact_match_goes_on_to_review_a_matching_address_keeping_both_reviews(): void
    {
        $officer = User::factory()->complianceOfficer()->create();
        $this->actingAs($officer);
        $run = $this->flaggedRun('contact', ['address_line1' => '666 Shadow Lane', 'postal_code' => '66666', 'country' => 'United States']);

        $this->post("/compliance/reviews/{$run->id}", ['step' => 'contact', 'decision' => 'approved']);

        $run->refresh();
        $this->assertSame('address_review', $run->status);
        $this->assertSame([self::ADDRESS_MATCH], $run->result['matches']);

        $this->get("/compliance/reviews/{$run->id}")->assertInertia(fn (Assert $page) => $page
            ->where('review.failed_step', 'address')
            ->has('review.earlier_reviews', 1)
            ->where('review.earlier_reviews.0.step', 'contact')
            ->where('review.earlier_reviews.0.matches', [self::PHONE_MATCH])
        );

        $this->post("/compliance/reviews/{$run->id}", ['step' => 'address', 'decision' => 'approved']);

        $run->refresh();
        $this->assertSame('approved', $run->status);
        $this->assertSame(['contact', 'address'], array_column($run->result['reviews'], 'step'));
        $this->assertSame(
            [['approve_contact', 'address_check', $officer->id], ['flag_address', 'address_review', null], ['approve_address', 'approved', $officer->id]],
            $run->transitions->map(fn ($step): array => [$step->transition, $step->to, $step->user_id])->all(),
        );
    }

    public function test_rejecting_requires_notes_and_ends_the_check(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $run = $this->flaggedRun('address');

        $this->post("/compliance/reviews/{$run->id}", ['step' => 'address', 'decision' => 'rejected'])
            ->assertSessionHasErrors(['notes' => 'Explain why you are rejecting this customer.']);
        $this->assertSame('address_review', $run->refresh()->status);

        $this->post("/compliance/reviews/{$run->id}", ['step' => 'address', 'decision' => 'rejected', 'notes' => 'Confirmed match'])
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $run->refresh()->status);
        $this->assertSame('rejected', $run->result['status']);
        $this->assertSame(['rejected', 'rejected'], $run->subject->auditLogs()->orderBy('id')->pluck('event')->all());
    }

    public function test_a_decision_on_a_step_the_run_has_moved_on_from_is_not_recorded(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $run = $this->flaggedRun('address');

        $this->from("/compliance/reviews/{$run->id}")
            ->post("/compliance/reviews/{$run->id}", ['step' => 'contact', 'decision' => 'approved'])
            ->assertRedirect('/compliance/reviews')
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('error', 'Someone else has already completed this review, so your decision wasn’t recorded.');

        $this->assertSame('address_review', $run->refresh()->status);
        $this->assertSame(0, $run->subject->auditLogs()->count());
    }

    public function test_a_review_someone_else_just_completed_is_not_recorded_twice(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $run = $this->flaggedRun('contact');
        $run->update(['status' => 'rejected']);

        $this->post("/compliance/reviews/{$run->id}", ['step' => 'contact', 'decision' => 'approved'])
            ->assertRedirect('/compliance/reviews')
            ->assertInertiaFlash('error', 'Someone else has already completed this review, so your decision wasn’t recorded.');

        $this->assertSame('rejected', $run->refresh()->status);
        $this->assertSame(0, $run->subject->auditLogs()->count());
    }

    public function test_an_officer_can_reassign_a_review_to_another_officer_who_is_emailed_and_returns_to_the_inbox(): void
    {
        Queue::fake([NotifyOfficerOfScreeningReview::class]);
        $olive = User::factory()->complianceOfficer()->create(['name' => 'Olive Officer']);
        $owen = User::factory()->complianceOfficer()->create(['name' => 'Owen Officer']);
        $this->actingAs($olive);
        $run = $this->flaggedRun('contact');
        $run->assignedTo()->associate($olive)->save();

        $this->from("/compliance/reviews/{$run->id}")
            ->put("/compliance/reviews/{$run->id}/assignee", ['step' => 'contact', 'assignee_id' => $owen->id])
            ->assertRedirect('/inbox')
            ->assertSessionHasNoErrors();

        $this->assertTrue($run->refresh()->assignedTo->is($owen));
        Queue::assertPushed(NotifyOfficerOfScreeningReview::class, fn (NotifyOfficerOfScreeningReview $job) => $job->officer->is($owen) && $job->assignedBy->is($olive) && $job->step === 'contact');

        $audit = $run->subject->auditLogs()->sole();
        $this->assertSame('assigned', $audit->event);
        $this->assertTrue($audit->user->is($olive));
        $this->assertSame(['review_assignee' => 'Olive Officer'], $audit->old_values);
        $this->assertSame(['review_assignee' => 'Owen Officer'], $audit->new_values);

        $this->get('/compliance/reviews')->assertInertia(fn (Assert $page) => $page->has('reviews', 0));
        $this->actingAs($owen)->get('/compliance/reviews')->assertInertia(fn (Assert $page) => $page->where('reviews.0.assignee', ['id' => $owen->id, 'name' => 'Owen Officer']));
        $this->get("/compliance/reviews/{$run->id}")->assertInertia(fn (Assert $page) => $page
            ->component('compliance/Review')
            ->where('review.assignee.name', 'Owen Officer')
            ->where('officers', [['id' => $olive->id, 'name' => 'Olive Officer']])
        );
    }

    public function test_an_officer_cannot_assign_a_review_to_themselves(): void
    {
        $officer = User::factory()->complianceOfficer()->create();
        $this->actingAs($officer);
        $run = $this->flaggedRun('address');

        $this->put("/compliance/reviews/{$run->id}/assignee", ['step' => 'address', 'assignee_id' => $officer->id])
            ->assertSessionHasErrors(['assignee_id' => 'Choose another compliance officer.']);

        $this->assertNull($run->refresh()->assigned_to_id);
    }

    public function test_unassigning_a_review_emails_nobody_and_returns_to_the_inbox(): void
    {
        Queue::fake([NotifyOfficerOfScreeningReview::class]);
        $officer = User::factory()->complianceOfficer()->create();
        $this->actingAs($officer);
        $run = $this->flaggedRun('address');
        $run->assignedTo()->associate($officer)->save();

        $this->put("/compliance/reviews/{$run->id}/assignee", ['step' => 'address', 'assignee_id' => null])
            ->assertRedirect('/inbox');

        $this->assertNull($run->refresh()->assigned_to_id);
        Queue::assertNothingPushed();
        $this->assertSame('assigned', $run->subject->auditLogs()->sole()->event);
    }

    public function test_a_review_can_only_be_assigned_to_a_compliance_officer(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $run = $this->flaggedRun('contact');

        $this->put("/compliance/reviews/{$run->id}/assignee", ['step' => 'contact', 'assignee_id' => User::factory()->create()->id])
            ->assertSessionHasErrors(['assignee_id' => 'Choose a compliance officer.']);

        $this->assertNull($run->refresh()->assigned_to_id);
    }

    public function test_a_completed_review_is_not_reassigned(): void
    {
        Queue::fake([NotifyOfficerOfScreeningReview::class]);
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $run = $this->flaggedRun('contact');
        $run->update(['status' => 'rejected']);

        $this->put("/compliance/reviews/{$run->id}/assignee", ['step' => 'contact', 'assignee_id' => User::factory()->complianceOfficer()->create()->id])
            ->assertRedirect('/compliance/reviews')
            ->assertInertiaFlash('error', 'This review has already been completed, so it wasn’t reassigned.');

        $this->assertNull($run->refresh()->assigned_to_id);
        Queue::assertNothingPushed();
    }

    public function test_the_address_review_after_an_approved_contact_match_starts_unassigned(): void
    {
        $officer = User::factory()->complianceOfficer()->create();
        $this->actingAs($officer);
        $run = $this->flaggedRun('contact', ['address_line1' => '666 Shadow Lane', 'postal_code' => '66666', 'country' => 'United States']);
        $run->assignedTo()->associate($officer)->save();

        $this->post("/compliance/reviews/{$run->id}", ['step' => 'contact', 'decision' => 'approved']);

        $this->assertSame('address_review', $run->refresh()->status);
        $this->assertNull($run->assigned_to_id);
    }

    /**
     * A verification run waiting for review of the given step. The customer's phone is on
     * the demo sanctions list; their address is too when the address step is flagged.
     *
     * @param  'contact'|'address'  $step
     * @param  array<string, mixed>  $customerAttributes
     */
    private function flaggedRun(string $step, array $customerAttributes = [], string $flaggedAt = '2026-10-28T21:00:00+00:00'): WorkflowInstance
    {
        $customer = Customer::factory()->create(['phone' => '+1 555 010 0666', ...$customerAttributes]);

        return $customer->workflows()->create([
            'workflow' => WorkflowInstance::CUSTOMER_VERIFICATION,
            'status' => "{$step}_review",
            'result' => [
                'status' => 'flagged',
                'failed_step' => $step,
                'matches' => [$step === 'contact' ? self::PHONE_MATCH : self::ADDRESS_MATCH],
                'flagged_at' => $flaggedAt,
            ],
        ]);
    }
}
