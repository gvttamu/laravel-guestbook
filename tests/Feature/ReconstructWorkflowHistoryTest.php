<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTransition;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReconstructWorkflowHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_rebuilds_a_reviewed_verification_run_from_its_audit_entries(): void
    {
        $engine = User::factory()->create();
        $officer = User::factory()->complianceOfficer()->create();
        $customer = Customer::factory()->create();
        $run = $this->runAt($customer, WorkflowInstance::CUSTOMER_VERIFICATION, 'approved', '2026-10-28 10:00:00');
        $this->auditAt($customer, '2026-10-28 10:01:00', 'flagged', ['status' => 'flagged', 'failed_step' => 'contact', 'matches' => ['A match']], $engine);
        $this->auditAt($customer, '2026-10-28 10:05:00', 'approved', ['review_step' => 'contact', 'review_decision' => 'approved', 'review_notes' => null], $officer);
        $this->auditAt($customer, '2026-10-28 10:06:00', 'approved', ['status' => 'approved', 'failed_step' => null, 'matches' => []], $engine);

        $this->artisan('workflows:reconstruct-history')->assertSuccessful();

        $this->assertSame([
            ['flag_contact', 'pending', 'contact_review', $engine->id, '2026-10-28 10:01:00', true],
            ['approve_contact', 'contact_review', 'address_check', $officer->id, '2026-10-28 10:05:00', true],
            ['clear_address', 'address_check', 'approved', $engine->id, '2026-10-28 10:06:00', true],
        ], $this->steps($run));
    }

    public function test_a_superseded_run_ends_when_the_next_run_started_and_entries_after_that_belong_to_the_next_run(): void
    {
        $customer = Customer::factory()->create();
        $superseded = $this->runAt($customer, WorkflowInstance::CUSTOMER_VERIFICATION, 'superseded', '2026-10-28 09:00:00');
        $this->auditAt($customer, '2026-10-28 09:01:00', 'flagged', ['status' => 'flagged', 'failed_step' => 'address', 'matches' => ['A match']]);
        $next = $this->runAt($customer, WorkflowInstance::CUSTOMER_VERIFICATION, 'verified', '2026-10-28 09:30:00');
        $this->auditAt($customer, '2026-10-28 09:31:00', 'verified', ['status' => 'verified', 'failed_step' => null, 'matches' => []]);
        $scoring = $this->runAt($customer, WorkflowInstance::FINANCIAL_SCORING, 'scored', '2026-10-28 09:30:00');
        $this->auditAt($customer, '2026-10-28 09:32:00', 'scored', ['score' => 85]);

        $this->artisan('workflows:reconstruct-history')->assertSuccessful();

        $this->assertSame([
            ['flag_address', 'pending', 'address_review', null, '2026-10-28 09:01:00', true],
            ['supersede', 'address_review', 'superseded', null, '2026-10-28 09:30:00', true],
        ], $this->steps($superseded));
        $this->assertSame([['verify', 'pending', 'verified', null, '2026-10-28 09:31:00', true]], $this->steps($next));
        $this->assertSame([['score', 'pending', 'scored', null, '2026-10-28 09:32:00', true]], $this->steps($scoring));
    }

    public function test_a_decision_from_the_engines_first_single_review_process_is_not_traced_as_a_later_step(): void
    {
        $customer = Customer::factory()->create();
        $run = $this->runAt($customer, WorkflowInstance::CUSTOMER_VERIFICATION, 'approved', '2026-10-28 09:00:00');
        $this->auditAt($customer, '2026-10-28 09:01:00', 'flagged', ['status' => 'flagged', 'failed_step' => 'contact', 'matches' => ['A match']]);
        $this->auditAt($customer, '2026-10-28 09:05:00', 'approved', ['review_decision' => 'approved', 'review_notes' => null]);

        $this->artisan('workflows:reconstruct-history')
            ->expectsOutputToContain("Run {$run->id} (customer-verification) could only be traced to contact_review of approved.")
            ->assertSuccessful();

        $this->assertSame([['flag_contact', 'pending', 'contact_review', null, '2026-10-28 09:01:00', true]], $this->steps($run));
    }

    public function test_runs_that_already_have_steps_are_left_alone(): void
    {
        $recorded = WorkflowTransition::factory()->create();

        $this->artisan('workflows:reconstruct-history')->assertSuccessful();
        $this->artisan('workflows:reconstruct-history')->assertSuccessful();

        $this->assertSame(1, WorkflowTransition::count());
        $this->assertFalse($recorded->refresh()->reconstructed);
    }

    public function test_reports_a_run_whose_entries_do_not_reach_its_status(): void
    {
        $customer = Customer::factory()->create();
        $run = $this->runAt($customer, WorkflowInstance::CUSTOMER_VERIFICATION, 'rejected', '2026-10-28 09:00:00');

        $this->artisan('workflows:reconstruct-history')
            ->expectsOutputToContain("Run {$run->id} (customer-verification) could only be traced to its start of rejected.")
            ->assertSuccessful();
    }

    private function runAt(Customer $customer, string $workflow, string $status, string $createdAt): WorkflowInstance
    {
        $this->travelTo($createdAt);

        return $customer->workflows()->create(['workflow' => $workflow, 'status' => $status]);
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function auditAt(Customer $customer, string $createdAt, string $event, array $newValues, ?User $user = null): void
    {
        $this->travelTo($createdAt);

        $customer->auditLogs()->create(['event' => $event, 'new_values' => $newValues, 'user_id' => $user?->id]);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: ?int, 4: string, 5: bool}>
     */
    private function steps(WorkflowInstance $run): array
    {
        return $run->transitions()->get()
            ->map(fn (WorkflowTransition $step): array => [$step->transition, $step->from, $step->to, $step->user_id, $step->created_at->toDateTimeString(), $step->reconstructed])
            ->all();
    }
}
