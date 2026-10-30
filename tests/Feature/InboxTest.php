<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FormDraft;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const FLOWABLE = 'http://flowable.test/process-api';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flowable.url' => self::FLOWABLE]);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/inbox')->assertRedirect('/login');
    }

    public function test_a_compliance_officer_sees_open_reviews_but_no_drafts(): void
    {
        $officer = User::factory()->complianceOfficer()->create();
        $this->actingAs($officer);
        $flagged = $this->flaggedWorkflow(['first_name' => 'Pete', 'last_name' => 'Smith']);
        Http::fake([self::FLOWABLE.'/runtime/tasks*' => Http::response(['data' => [
            ['id' => 'task-1', 'taskDefinitionKey' => 'reviewContact', 'processInstanceId' => $flagged->external_id, 'createTime' => '2026-10-28T21:00:00.000Z'],
        ]])]);
        FormDraft::factory()->for($officer)->create();

        $this->get('/inbox')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox')
                ->where('engineAvailable', true)
                ->has('items', 1)
                ->where('items.0.type', 'sanctions_review')
                ->where('items.0.customer.first_name', 'Pete')
                ->where('items.0.failed_step', 'contact')
                ->where('items.0.matches', ['Phone number +1 555 010 0666 is on the Demo sanctions list'])
                ->where('items.0.url', route('compliance.reviews.show', 'task-1'))
                ->where('inboxCount', 1)
            );
    }

    public function test_users_who_capture_customers_see_their_own_drafts_oldest_first_without_asking_the_engine(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Http::fake();
        $this->flaggedWorkflow([]);
        $this->travelTo('2026-10-28 20:00:00');
        $newCustomer = FormDraft::factory()->for($user)->create(['payload' => ['first_name' => 'Ada'], 'current_step' => 'details']);
        $this->travelTo('2026-10-29 09:00:00');
        $edited = Customer::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);
        $changes = FormDraft::factory()->for($user)->editing($edited, ['city' => 'Paris'])->create(['current_step' => 'financials']);
        FormDraft::factory()->create();

        $this->get('/inbox')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('engineAvailable', true)
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

        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), self::FLOWABLE));
    }

    public function test_every_page_shows_how_many_items_are_in_the_inbox(): void
    {
        $officer = User::factory()->complianceOfficer()->create();
        $user = User::factory()->create();
        FormDraft::factory()->for($officer)->count(2)->create();
        FormDraft::factory()->for($user)->create();
        $this->flaggedWorkflow([]);
        $this->flaggedWorkflow([])->update(['status' => 'running']);
        Http::fake();

        $this->actingAs($officer)->get('/')->assertInertia(fn (Assert $page) => $page->where('inboxCount', 1));
        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page->where('inboxCount', 1));
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), self::FLOWABLE));
    }

    public function test_the_inbox_says_so_and_counts_what_it_shows_when_the_engine_is_unreachable(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        $this->flaggedWorkflow([]);

        $this->get('/inbox')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('engineAvailable', false)
                ->has('items', 0)
                ->where('inboxCount', 0)
            );
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
    private function flaggedWorkflow(array $customerAttributes): WorkflowInstance
    {
        return Customer::factory()->create($customerAttributes)->workflow()->create([
            'workflow' => WorkflowInstance::CUSTOMER_VERIFICATION,
            'external_id' => 'process-'.fake()->unique()->randomNumber(5),
            'status' => 'flagged',
            'result' => [
                'status' => 'flagged',
                'failed_step' => 'contact',
                'matches' => ['Phone number +1 555 010 0666 is on the Demo sanctions list'],
            ],
        ]);
    }
}
