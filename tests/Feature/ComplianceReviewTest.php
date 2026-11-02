<?php

namespace Tests\Feature;

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

    public function test_a_run_that_is_not_awaiting_review_is_not_found(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $verified = Customer::factory()->create()->workflows()->create(['workflow' => WorkflowInstance::CUSTOMER_VERIFICATION, 'status' => 'verified']);

        $this->get("/compliance/reviews/{$verified->id}")->assertNotFound();
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
