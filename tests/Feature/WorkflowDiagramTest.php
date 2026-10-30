<?php

namespace Tests\Feature;

use App\Jobs\ScoreCustomerFinancials;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkflowDiagramTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/workflows/financial-scoring')->assertRedirect('/login');
    }

    public function test_shows_the_scoring_workflow_as_a_diagram_of_its_steps(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/workflows/financial-scoring')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workflows/Show')
                ->where('title', 'Financial scoring')
                ->where('run', null)
                ->where('diagram', fn (string $diagram): bool => str_starts_with($diagram, 'graph LR')
                    && str_contains($diagram, 'place0(["Waiting to be scored"])')
                    && str_contains($diagram, 'place0-->|"Score affordability"|place1')
                    && str_contains($diagram, 'place0-->|"Financials changed"|place2')
                    && ! str_contains($diagram, 'style place'))
                ->where('places', ['pending', 'scored', 'superseded'])
            );
    }

    public function test_gives_the_steps_a_run_has_taken_starting_where_every_run_starts(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-30 09:00:00');
        $customer = Customer::factory()->create(['first_name' => 'Ada']);
        $run = $customer->workflows()->create(['workflow' => WorkflowInstance::FINANCIAL_SCORING, 'status' => 'pending']);
        $this->travelTo('2026-10-30 09:00:05');
        ScoreCustomerFinancials::dispatch($run);

        $this->get("/workflows/financial-scoring?run={$run->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.status', 'scored')
                ->where('run.customer.first_name', 'Ada')
                ->where('run.history', [
                    ['place' => 'pending', 'label' => 'Waiting to be scored', 'at' => '2026-10-30T09:00:00+00:00', 'transition' => null, 'by' => null, 'reconstructed' => false],
                    ['place' => 'scored', 'label' => 'Scored', 'at' => '2026-10-30T09:00:05+00:00', 'transition' => 'Score affordability', 'by' => null, 'reconstructed' => false],
                ])
            );
    }

    public function test_a_run_of_another_workflow_or_an_unknown_workflow_is_not_found(): void
    {
        $this->actingAs(User::factory()->create());
        $verification = Customer::factory()->create()->workflows()->create(['workflow' => WorkflowInstance::CUSTOMER_VERIFICATION, 'status' => 'pending']);

        $this->get("/workflows/financial-scoring?run={$verification->id}")->assertNotFound();
        $this->get('/workflows/onboarding')->assertNotFound();
    }

    public function test_shows_the_verification_workflow_with_its_review_steps(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $run = Customer::factory()->create()->workflows()->create(['workflow' => WorkflowInstance::CUSTOMER_VERIFICATION, 'status' => 'contact_review']);

        $this->get("/workflows/customer-verification?run={$run->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('title', 'Customer verification')
                ->where('run.status', 'contact_review')
                ->where('diagram', fn (string $diagram): bool => str_contains($diagram, 'place1(("Contact match: awaiting review"))')
                    && str_contains($diagram, 'place1-->|"Compliance approves"|place2'))
            );
    }
}
