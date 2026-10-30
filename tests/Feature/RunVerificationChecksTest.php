<?php

namespace Tests\Feature;

use App\Jobs\RunVerificationChecks;
use App\Models\Customer;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RunVerificationChecksTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_customer_with_clear_contact_details_and_address_is_verified(): void
    {
        $run = $this->verificationRun($this->customer());

        RunVerificationChecks::dispatch($run);

        $run->refresh();
        $this->assertSame('verified', $run->status);
        $this->assertSame('verified', $run->result['status']);

        $audit = $run->subject->auditLogs()->sole();
        $this->assertSame('verified', $audit->event);
        $this->assertNull($audit->user_id);
    }

    public function test_a_sanctioned_phone_number_waits_for_review_of_the_contact_match(): void
    {
        $this->travelTo('2026-10-30 10:00:00');
        $run = $this->verificationRun($this->customer(['phone' => '+1 555 010 0666']));

        RunVerificationChecks::dispatch($run);

        $run->refresh();
        $this->assertSame('contact_review', $run->status);
        $this->assertSame('contact', $run->result['failed_step']);
        $this->assertSame(['Phone number +1 555 010 0666 is on the Demo sanctions list'], $run->result['matches']);
        $this->assertSame('2026-10-30T10:00:00+00:00', $run->result['flagged_at']);

        $audit = $run->subject->auditLogs()->sole();
        $this->assertSame('flagged', $audit->event);
        $this->assertSame(['status' => 'flagged', 'failed_step' => 'contact', 'matches' => $run->result['matches']], $audit->new_values);
    }

    public function test_clear_contact_details_with_a_sanctioned_address_wait_for_review_of_the_address_match(): void
    {
        $run = $this->verificationRun($this->customer(['address_line1' => '13 Blackwater Rd', 'postal_code' => 'EC1A 1BB', 'country' => 'United Kingdom']));

        RunVerificationChecks::dispatch($run);

        $run->refresh();
        $this->assertSame('address_review', $run->status);
        $this->assertSame('address', $run->result['failed_step']);
    }

    public function test_a_run_that_is_not_waiting_for_checks_is_left_alone(): void
    {
        $customer = $this->customer(['phone' => '+1 555 010 0666']);
        $superseded = $this->verificationRun($customer);
        $superseded->supersede();
        $verified = $this->verificationRun($customer, 'verified');

        RunVerificationChecks::dispatch($superseded);
        RunVerificationChecks::dispatch($verified);

        $this->assertSame('superseded', $superseded->refresh()->status);
        $this->assertSame('verified', $verified->refresh()->status);
        $this->assertSame(0, $customer->auditLogs()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create([
            'phone' => '+44 20 7946 0000',
            'email' => 'ada@example.com',
            'address_line1' => '12 Analytical Row',
            'postal_code' => 'N1 9GU',
            'country' => 'United Kingdom',
            ...$attributes,
        ]);
    }

    private function verificationRun(Customer $customer, string $status = 'pending'): WorkflowInstance
    {
        return $customer->workflows()->create(['workflow' => WorkflowInstance::CUSTOMER_VERIFICATION, 'status' => $status]);
    }
}
