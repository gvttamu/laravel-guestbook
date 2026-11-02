<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreditReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REFERRAL_REASONS = ['Credit score 640 is below 700, the score for automatic approval'];

    public function test_lists_referred_applications_longest_waiting_first_with_the_customer_and_scores(): void
    {
        $this->actingAs(User::factory()->creditOfficer()->create());
        $later = $this->referredRun(['first_name' => 'Grace'], referredAt: '2026-11-02T09:00:00+00:00');
        $earlier = $this->referredRun(['first_name' => 'Ada', 'loan_amount' => 5000, 'loan_term_months' => 24], referredAt: '2026-11-01T09:00:00+00:00');
        $this->scoringRun(Customer::factory()->create(), 'approved');

        $this->get('/credit/reviews')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('credit/Reviews')
                ->has('reviews', 2)
                ->where('reviews.0.id', $earlier->id)
                ->where('reviews.0.customer.first_name', 'Ada')
                ->where('reviews.0.loan_amount', '5000.00')
                ->where('reviews.0.loan_term_months', 24)
                ->where('reviews.0.score', 100)
                ->where('reviews.0.credit_score', 640)
                ->where('reviews.0.engine_reasons', self::REFERRAL_REASONS)
                ->where('reviews.1.id', $later->id)
            );
    }

    public function test_shows_a_referred_application_with_the_customers_finances_and_scores(): void
    {
        $this->actingAs(User::factory()->creditOfficer()->create());
        $run = $this->referredRun(['first_name' => 'Ada', 'monthly_income' => 6000, 'loan_purpose' => 'car']);

        $this->get("/credit/reviews/{$run->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('credit/Review')
                ->where('review.id', $run->id)
                ->where('review.customer.first_name', 'Ada')
                ->where('review.customer.monthly_income', '6000.00')
                ->where('review.customer.loan_purpose', 'car')
                ->missing('review.customer.address_line1')
                ->where('review.score', 100)
                ->where('review.risk_band', 'low')
                ->where('review.credit_score', 640)
                ->where('review.engine_decision', 'referred')
                ->where('review.decisions', [])
            );
    }

    public function test_opening_a_referred_application_assigns_it_to_the_officer_so_others_see_a_summary(): void
    {
        $carla = User::factory()->creditOfficer()->create(['name' => 'Carla Credit']);
        $colin = User::factory()->creditOfficer()->create();
        $run = $this->referredRun();

        $this->actingAs($carla)->get("/credit/reviews/{$run->id}")->assertInertia(fn (Assert $page) => $page->component('credit/Review'));

        $this->assertTrue($run->refresh()->assignedTo->is($carla));
        $audit = $run->subject->auditLogs()->sole();
        $this->assertSame('assigned', $audit->event);
        $this->assertTrue($audit->user->is($carla));
        $this->assertSame(['review_assignee' => 'Carla Credit'], $audit->new_values);

        $this->actingAs($colin)->get("/credit/reviews/{$run->id}")->assertInertia(fn (Assert $page) => $page
            ->component('credit/ReviewSummary')
            ->where('review.assigned_to', 'Carla Credit')
            ->where('review.can_reconsider', false)
            ->missing('review.customer.monthly_income')
        );
        $this->get('/credit/reviews')->assertInertia(fn (Assert $page) => $page->has('reviews', 0));
        $this->get('/inbox')->assertInertia(fn (Assert $page) => $page->has('items', 0)->where('inboxCount', 0));

        $this->actingAs($carla)->get('/credit/reviews')->assertInertia(fn (Assert $page) => $page->where('reviews.0.id', $run->id));
    }

    public function test_officers_see_referred_applications_up_to_their_credit_risk_tier(): void
    {
        $low = $this->referredRun(['first_name' => 'Lou'], tier: 'low', points: 1);
        $high = $this->referredRun(['first_name' => 'Hal'], tier: 'high', points: 5);
        $highest = $this->referredRun(['first_name' => 'Hil'], tier: 'highest', points: 7);

        $this->actingAs(User::factory()->creditOfficer('low')->create());
        $this->get('/credit/reviews')->assertInertia(fn (Assert $page) => $page->has('reviews', 1)->where('reviews.0.id', $low->id));
        $this->get('/inbox')->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.credit_risk_tier', 'low')->where('inboxCount', 1));

        $this->actingAs(User::factory()->creditOfficer('high')->create());
        $this->get('/credit/reviews')->assertInertia(fn (Assert $page) => $page->where('reviews', fn ($reviews) => collect($reviews)->pluck('id')->all() === [$high->id, $low->id]));

        $this->actingAs(User::factory()->creditOfficer('highest')->create());
        $this->get('/credit/reviews')->assertInertia(fn (Assert $page) => $page->has('reviews', 3)->where('reviews.0.id', $highest->id));
        $this->get('/inbox')->assertInertia(fn (Assert $page) => $page->has('items', 3)->where('inboxCount', 3));
    }

    public function test_applications_are_listed_riskiest_first_then_oldest_first(): void
    {
        $this->actingAs(User::factory()->creditOfficer('highest')->create());
        $newerRisky = $this->referredRun(referredAt: '2026-11-02T09:00:00+00:00', tier: 'high', points: 5);
        $olderSafe = $this->referredRun(referredAt: '2026-10-01T09:00:00+00:00', tier: 'low', points: 0);
        $olderRisky = $this->referredRun(referredAt: '2026-11-01T09:00:00+00:00', tier: 'high', points: 5);
        $middle = $this->referredRun(referredAt: '2026-10-15T09:00:00+00:00', tier: 'medium', points: 3);
        $expected = [$olderRisky->id, $newerRisky->id, $middle->id, $olderSafe->id];

        $this->get('/credit/reviews')->assertInertia(fn (Assert $page) => $page
            ->where('reviews', fn ($reviews) => collect($reviews)->pluck('id')->all() === $expected)
        );
        $this->get('/inbox')->assertInertia(fn (Assert $page) => $page
            ->where('items', fn ($items) => collect($items)->pluck('url')->all() === array_map(fn (int $id): string => route('credit.reviews.show', $id), $expected))
        );
    }

    public function test_an_application_above_the_officers_tier_shows_a_summary_and_is_not_assigned_to_them(): void
    {
        $this->actingAs(User::factory()->creditOfficer('medium')->create());
        $run = $this->referredRun(tier: 'high');

        $this->get("/credit/reviews/{$run->id}")->assertInertia(fn (Assert $page) => $page
            ->component('credit/ReviewSummary')
            ->where('review.above_tier', true)
            ->where('review.credit_risk_tier', 'high')
        );

        $this->assertNull($run->refresh()->assigned_to_id);
        $this->assertSame(0, $run->subject->auditLogs()->count());
    }

    public function test_an_application_above_the_officers_tier_cannot_be_decided_but_one_below_can(): void
    {
        $this->actingAs(User::factory()->creditOfficer('high')->create());
        $highest = $this->referredRun(tier: 'highest');
        $low = $this->referredRun(tier: 'low');

        $this->post("/credit/reviews/{$highest->id}", ['decision' => 'approved'])
            ->assertRedirect('/credit/reviews')
            ->assertInertiaFlash('error', 'This is a highest-risk application, above your credit risk tier, so your decision wasn’t recorded.');
        $this->assertSame('credit_review', $highest->refresh()->status);

        $this->post("/credit/reviews/{$low->id}", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $low->refresh()->status);
    }

    public function test_opening_a_decided_application_assigns_it_to_nobody(): void
    {
        $this->actingAs(User::factory()->creditOfficer()->create());
        $run = $this->scoringRun($this->customer(), 'approved');

        $this->get("/credit/reviews/{$run->id}")->assertInertia(fn (Assert $page) => $page->component('credit/ReviewSummary'));

        $this->assertNull($run->refresh()->assigned_to_id);
        $this->assertSame(0, $run->subject->auditLogs()->count());
    }

    public function test_an_application_assigned_to_another_officer_cannot_be_decided(): void
    {
        $this->actingAs(User::factory()->creditOfficer()->create());
        $run = $this->referredRun();
        $run->assignedTo()->associate(User::factory()->creditOfficer()->create(['name' => 'Colin Credit']))->save();

        $this->post("/credit/reviews/{$run->id}", ['decision' => 'approved'])
            ->assertRedirect('/credit/reviews')
            ->assertInertiaFlash('error', 'This application is assigned to Colin Credit, so your decision wasn’t recorded.');

        $this->assertSame('credit_review', $run->refresh()->status);
        $this->assertSame(0, $run->subject->auditLogs()->count());
    }

    public function test_deciding_clears_the_assignment_so_a_reconsidered_application_goes_back_to_the_queue_unassigned(): void
    {
        $officer = User::factory()->creditOfficer()->create();
        $this->actingAs($officer);
        $run = $this->referredRun();
        $run->assignedTo()->associate($officer)->save();

        $this->post("/credit/reviews/{$run->id}", ['decision' => 'approved']);
        $this->assertNull($run->refresh()->assigned_to_id);

        $this->post("/credit/reviews/{$run->id}/reconsider", ['notes' => 'New payslips provided']);
        $run->refresh();
        $this->assertSame('credit_review', $run->status);
        $this->assertNull($run->assigned_to_id);
    }

    public function test_approving_a_referred_application_records_the_decision_and_who_made_it(): void
    {
        $officer = User::factory()->creditOfficer()->create(['name' => 'Carla Credit']);
        $this->actingAs($officer);
        $run = $this->referredRun();

        $this->post("/credit/reviews/{$run->id}", ['decision' => 'approved', 'notes' => 'Stable income'])
            ->assertRedirect('/credit/reviews')
            ->assertSessionHasNoErrors();

        $run->refresh();
        $this->assertSame('approved', $run->status);
        $this->assertSame([['approve', 'approved', $officer->id]], $run->transitions->map(fn ($step): array => [$step->transition, $step->to, $step->user_id])->all());

        $audit = $run->subject->auditLogs()->sole();
        $this->assertSame('loan_approved', $audit->event);
        $this->assertTrue($audit->user->is($officer));
        $this->assertSame(['loan_decision' => 'approved', 'loan_decision_notes' => 'Stable income'], $audit->new_values);

        $this->get("/credit/reviews/{$run->id}")->assertInertia(fn (Assert $page) => $page
            ->component('credit/ReviewSummary')
            ->where('review.status_label', 'Approved')
            ->where('review.can_reconsider', true)
            ->where('review.decisions.0.action', 'approved')
            ->where('review.decisions.0.notes', 'Stable income')
            ->where('review.decisions.0.reviewed_by', 'Carla Credit')
        );
    }

    public function test_rejecting_requires_notes(): void
    {
        $this->actingAs(User::factory()->creditOfficer()->create());
        $run = $this->referredRun();

        $this->post("/credit/reviews/{$run->id}", ['decision' => 'rejected'])
            ->assertSessionHasErrors(['notes' => 'Explain why you are rejecting this application.']);
        $this->assertSame('credit_review', $run->refresh()->status);

        $this->post("/credit/reviews/{$run->id}", ['decision' => 'rejected', 'notes' => 'Too many missed payments'])
            ->assertSessionHasNoErrors();
        $this->assertSame('rejected', $run->refresh()->status);
    }

    public function test_an_application_someone_else_just_decided_is_not_decided_twice(): void
    {
        $this->actingAs(User::factory()->creditOfficer()->create());
        $run = $this->referredRun();
        $run->update(['status' => 'rejected']);

        $this->post("/credit/reviews/{$run->id}", ['decision' => 'approved'])
            ->assertRedirect('/credit/reviews')
            ->assertInertiaFlash('error', 'Someone else has already decided this application, so your decision wasn’t recorded.');

        $this->assertSame('rejected', $run->refresh()->status);
        $this->assertSame(0, $run->subject->auditLogs()->count());
    }

    public function test_reconsidering_a_decided_application_refers_it_again_with_the_reason(): void
    {
        $officer = User::factory()->creditOfficer()->create(['name' => 'Carla Credit']);
        $this->actingAs($officer);
        $this->travelTo('2026-11-02 10:00:00');
        $run = $this->scoringRun($this->customer(), 'rejected');

        $this->post("/credit/reviews/{$run->id}/reconsider", ['notes' => 'New payslips provided'])
            ->assertRedirect("/credit/reviews/{$run->id}")
            ->assertSessionHasNoErrors();

        $run->refresh();
        $this->assertSame('credit_review', $run->status);
        $this->assertSame('2026-11-02T10:00:00+00:00', $run->result['referred_at']);
        $this->assertSame([['reconsider', 'credit_review', $officer->id]], $run->transitions->map(fn ($step): array => [$step->transition, $step->to, $step->user_id])->all());
        $this->assertSame('loan_reconsidered', $run->subject->auditLogs()->sole()->event);

        $this->get("/credit/reviews/{$run->id}")->assertInertia(fn (Assert $page) => $page
            ->component('credit/Review')
            ->where('review.decisions.0.action', 'reconsidered')
            ->where('review.decisions.0.notes', 'New payslips provided')
        );
        $this->get('/credit/reviews')->assertInertia(fn (Assert $page) => $page->where('reviews.0.id', $run->id));
    }

    public function test_reconsidering_requires_a_reason(): void
    {
        $this->actingAs(User::factory()->creditOfficer()->create());
        $run = $this->scoringRun($this->customer(), 'approved');

        $this->post("/credit/reviews/{$run->id}/reconsider", ['notes' => ''])
            ->assertSessionHasErrors(['notes' => 'Explain why you are reconsidering this application.']);

        $this->assertSame('approved', $run->refresh()->status);
    }

    public function test_an_application_replaced_by_a_newer_one_or_not_yet_decided_cannot_be_reconsidered(): void
    {
        $this->actingAs(User::factory()->creditOfficer()->create());
        $customer = $this->customer();
        $old = $this->scoringRun($customer, 'approved');
        $this->scoringRun($customer, 'pending');
        $referred = $this->referredRun();

        $this->get("/credit/reviews/{$old->id}")->assertInertia(fn (Assert $page) => $page->where('review.can_reconsider', false));

        foreach ([$old, $referred] as $run) {
            $this->post("/credit/reviews/{$run->id}/reconsider", ['notes' => 'Look again'])
                ->assertRedirect("/credit/reviews/{$run->id}")
                ->assertInertiaFlash('error', 'This application can’t be reconsidered: it’s no longer decided, or the customer’s financials have changed since.');
        }

        $this->assertSame('approved', $old->refresh()->status);
        $this->assertSame('credit_review', $referred->refresh()->status);
    }

    public function test_an_unknown_run_or_a_run_of_another_workflow_is_not_found(): void
    {
        $this->actingAs(User::factory()->creditOfficer()->create());
        $verification = $this->customer()->workflows()->create(['workflow' => WorkflowInstance::CUSTOMER_VERIFICATION, 'status' => 'verified']);

        $this->get("/credit/reviews/{$verification->id}")->assertNotFound();
        $this->get('/credit/reviews/999')->assertNotFound();
    }

    public function test_credit_reviews_are_forbidden_to_everyone_but_credit_officers(): void
    {
        $run = $this->referredRun();

        foreach ([User::factory()->create(), User::factory()->complianceOfficer()->create()] as $user) {
            $this->actingAs($user);

            $this->get('/credit/reviews')->assertForbidden();
            $this->get("/credit/reviews/{$run->id}")->assertForbidden();
            $this->post("/credit/reviews/{$run->id}", ['decision' => 'approved'])->assertForbidden();
            $this->post("/credit/reviews/{$run->id}/reconsider", ['notes' => 'Look again'])->assertForbidden();
        }

        $this->assertSame('credit_review', $run->refresh()->status);
    }

    public function test_credit_officers_cannot_capture_customers(): void
    {
        $this->actingAs(User::factory()->creditOfficer()->create());

        $this->get('/customers')->assertForbidden();
        $this->get('/customers/create')->assertForbidden();
    }

    /**
     * An application the decision engine referred: affordable (100, low risk), but with a credit
     * score of 640, in the low credit risk tier.
     *
     * @param  array<string, mixed>  $customerAttributes
     */
    private function referredRun(array $customerAttributes = [], string $referredAt = '2026-11-01T09:00:00+00:00', string $tier = 'low', int $points = 1): WorkflowInstance
    {
        return $this->scoringRun($this->customer($customerAttributes), 'credit_review', [
            'referred_at' => $referredAt,
            'credit_risk_tier' => $tier,
            'credit_risk_points' => $points,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function scoringRun(Customer $customer, string $status, array $result = []): WorkflowInstance
    {
        return $customer->workflows()->create([
            'workflow' => WorkflowInstance::FINANCIAL_SCORING,
            'status' => $status,
            'result' => [
                'score' => 100,
                'risk_band' => 'low',
                'reasons' => ['Rent, car and loan payments take 24% of income'],
                'loan_repayment' => 222.44,
                'credit_bureau' => 'Demo credit bureau',
                'credit_score' => 640,
                'engine_decision' => 'referred',
                'engine_reasons' => self::REFERRAL_REASONS,
                'credit_risk_tier' => 'low',
                'credit_risk_reasons' => ['Credit score 640: +1'],
                'reviews' => [],
                ...$result,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create($attributes);
    }
}
