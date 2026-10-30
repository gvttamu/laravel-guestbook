<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ComplianceReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const FLOWABLE = 'http://flowable.test/process-api';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flowable.url' => self::FLOWABLE]);
    }

    public function test_lists_open_review_tasks_with_the_flagged_customer(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $workflow = $this->flaggedWorkflow(['first_name' => 'Pete']);
        Http::fake([self::FLOWABLE.'/runtime/tasks*' => Http::response(['data' => [
            $this->task('task-1', $workflow->external_id),
            $this->task('task-for-an-unknown-process', 'process-unknown'),
        ]])]);

        $this->get('/compliance/reviews')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('compliance/Reviews')
                ->where('engineAvailable', true)
                ->has('reviews', 1)
                ->where('reviews.0.id', 'task-1')
                ->where('reviews.0.customer.first_name', 'Pete')
                ->where('reviews.0.failed_step', 'contact')
                ->where('reviews.0.matches', ['Phone number +1 555 010 0666 is on the Demo sanctions list'])
            );

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'processDefinitionKey=customerVerification')
            && str_contains($request->url(), 'candidateGroup=compliance'));
    }

    public function test_a_superseded_runs_review_is_not_offered(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $workflow = $this->flaggedWorkflow();
        $workflow->update(['status' => 'superseded']);
        Http::fake([
            self::FLOWABLE.'/runtime/tasks/task-1' => Http::response($this->task('task-1', $workflow->external_id)),
            self::FLOWABLE.'/runtime/tasks*' => Http::response(['data' => [$this->task('task-1', $workflow->external_id)]]),
        ]);

        $this->get('/compliance/reviews')->assertInertia(fn (Assert $page) => $page->has('reviews', 0));
        $this->get('/compliance/reviews/task-1')->assertNotFound();
    }

    public function test_the_list_says_so_when_the_engine_is_unreachable(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->get('/compliance/reviews')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('engineAvailable', false)->has('reviews', 0));
    }

    public function test_shows_a_review_with_the_customers_details(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $workflow = $this->flaggedWorkflow(['first_name' => 'Pete', 'city' => 'Testville']);
        Http::fake([self::FLOWABLE.'/runtime/tasks/task-1' => Http::response($this->task('task-1', $workflow->external_id))]);

        $this->get('/compliance/reviews/task-1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('compliance/Review')
                ->where('review.id', 'task-1')
                ->where('review.customer.city', 'Testville')
                ->where('review.failed_step', 'contact')
                ->where('review.earlier_reviews', [])
            );
    }

    public function test_a_finished_or_unrelated_task_is_not_found(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $workflow = $this->flaggedWorkflow();
        Http::fake([
            self::FLOWABLE.'/runtime/tasks/finished' => Http::response(['message' => 'Not found'], 404),
            self::FLOWABLE.'/runtime/tasks/other-task' => Http::response([...$this->task('other-task', $workflow->external_id), 'taskDefinitionKey' => 'somethingElse']),
        ]);

        $this->get('/compliance/reviews/finished')->assertNotFound();
        $this->get('/compliance/reviews/other-task')->assertNotFound();
    }

    public function test_approving_completes_the_task_records_the_decision_and_leaves_the_engine_to_finish(): void
    {
        $reviewer = User::factory()->complianceOfficer()->create();
        $this->actingAs($reviewer);
        $workflow = $this->flaggedWorkflow();
        $this->fakeTask('task-1', $workflow->external_id);

        $this->post('/compliance/reviews/task-1', ['decision' => 'approved', 'notes' => 'Different person, same number'])
            ->assertRedirect('/compliance/reviews');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::FLOWABLE.'/runtime/tasks/task-1'
            && $request['action'] === 'complete'
            && $request['variables'] === [
                ['name' => 'reviewDecision', 'value' => 'approved'],
                ['name' => 'reviewNotes', 'value' => 'Different person, same number'],
            ]);

        $workflow->refresh();
        $this->assertSame('running', $workflow->status);
        $this->assertSame('contact', $workflow->result['failed_step']);
        $this->assertCount(1, $workflow->result['reviews']);
        $this->assertSame('contact', $workflow->result['reviews'][0]['step']);
        $this->assertSame(['Phone number +1 555 010 0666 is on the Demo sanctions list'], $workflow->result['reviews'][0]['matches']);
        $this->assertSame('approved', $workflow->result['reviews'][0]['decision']);
        $this->assertSame('Different person, same number', $workflow->result['reviews'][0]['notes']);
        $this->assertSame($reviewer->id, $workflow->result['reviews'][0]['reviewed_by']);

        $audit = $workflow->subject->auditLogs()->sole();
        $this->assertSame('approved', $audit->event);
        $this->assertSame(['review_step' => 'contact', 'review_decision' => 'approved', 'review_notes' => 'Different person, same number'], $audit->new_values);
    }

    public function test_an_address_review_after_an_approved_contact_review_keeps_both_reviews(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $contactReviewer = User::factory()->complianceOfficer()->create(['name' => 'Grace Hopper']);
        $workflow = $this->flaggedWorkflow();
        $workflow->update(['result' => [
            'status' => 'flagged',
            'failed_step' => 'address',
            'matches' => ['Address 13 Blackwater Road, EC1A 1BB, United Kingdom is on the Demo sanctions list'],
            'reviews' => [[
                'step' => 'contact',
                'matches' => ['Phone number +1 555 010 0666 is on the Demo sanctions list'],
                'decision' => 'approved',
                'notes' => 'Shared number, different person',
                'reviewed_by' => $contactReviewer->id,
                'reviewed_at' => '2026-10-29T09:00:00+00:00',
            ]],
        ]]);
        $this->fakeTask('task-2', $workflow->external_id, 'reviewAddress');

        $this->get('/compliance/reviews/task-2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('review.failed_step', 'address')
                ->has('review.earlier_reviews', 1)
                ->where('review.earlier_reviews.0.step', 'contact')
                ->where('review.earlier_reviews.0.decision', 'approved')
                ->where('review.earlier_reviews.0.matches', ['Phone number +1 555 010 0666 is on the Demo sanctions list'])
                ->where('review.earlier_reviews.0.notes', 'Shared number, different person')
                ->where('review.earlier_reviews.0.reviewed_by', 'Grace Hopper')
            );

        $this->post('/compliance/reviews/task-2', ['decision' => 'rejected', 'notes' => 'Known shell company address'])
            ->assertRedirect('/compliance/reviews');

        $reviews = $workflow->refresh()->result['reviews'];
        $this->assertSame(['contact', 'address'], array_column($reviews, 'step'));
        $this->assertSame(['approved', 'rejected'], array_column($reviews, 'decision'));
        $this->assertSame('running', $workflow->status);
    }

    public function test_a_review_left_from_the_old_single_review_process_still_works_and_its_decision_is_final(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $workflow = $this->flaggedWorkflow();
        $task = $this->task('task-1', $workflow->external_id, 'reviewMatch');
        Http::fake(fn (Request $request) => match (true) {
            $request->method() === 'POST' => Http::response(null, 204),
            str_contains($request->url(), '/runtime/tasks/task-1') => Http::response($task),
            default => Http::response(['data' => [$task]]),
        });

        $this->get('/compliance/reviews')->assertInertia(fn (Assert $page) => $page->has('reviews', 1));
        $this->post('/compliance/reviews/task-1', ['decision' => 'approved'])->assertRedirect('/compliance/reviews');

        $workflow->refresh();
        $this->assertSame('approved', $workflow->status);
        $this->assertSame('contact', $workflow->result['reviews'][0]['step']);
    }

    public function test_a_review_task_for_a_step_the_customer_is_not_flagged_at_is_not_offered(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $workflow = $this->flaggedWorkflow();
        Http::fake([
            self::FLOWABLE.'/runtime/tasks/task-1' => Http::response($this->task('task-1', $workflow->external_id, 'reviewAddress')),
            self::FLOWABLE.'/runtime/tasks*' => Http::response(['data' => [$this->task('task-1', $workflow->external_id, 'reviewAddress')]]),
        ]);

        $this->get('/compliance/reviews')->assertInertia(fn (Assert $page) => $page->has('reviews', 0));
        $this->get('/compliance/reviews/task-1')->assertNotFound();
    }

    public function test_rejecting_requires_notes(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $workflow = $this->flaggedWorkflow();
        $this->fakeTask('task-1', $workflow->external_id);

        $this->from('/compliance/reviews/task-1')
            ->post('/compliance/reviews/task-1', ['decision' => 'rejected', 'notes' => ''])
            ->assertRedirect('/compliance/reviews/task-1')
            ->assertSessionHasErrors(['notes' => 'Explain why you are rejecting this customer.']);

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $this->assertSame('flagged', $workflow->refresh()->status);
    }

    public function test_a_review_someone_else_just_completed_is_not_recorded_twice(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create());
        $workflow = $this->flaggedWorkflow();
        Http::fake(fn (Request $request) => $request->method() === 'GET'
            ? Http::response($this->task('task-1', $workflow->external_id))
            : Http::response(['message' => 'Could not find a task with id task-1'], 404));

        $this->from('/compliance/reviews/task-1')
            ->post('/compliance/reviews/task-1', ['decision' => 'approved'])
            ->assertRedirect('/compliance/reviews/task-1')
            ->assertSessionHasErrors(['decision' => 'Someone else has already completed this review.']);

        $this->assertSame('flagged', $workflow->refresh()->status);
        $this->assertSame(0, $workflow->subject->auditLogs()->count());
    }

    /**
     * @param  array<string, mixed>  $customerAttributes
     */
    private function flaggedWorkflow(array $customerAttributes = []): WorkflowInstance
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

    /**
     * Flowable's REST representation of an open review task.
     *
     * @return array<string, mixed>
     */
    private function task(string $id, string $processInstanceId, string $definitionKey = 'reviewContact'): array
    {
        return [
            'id' => $id,
            'name' => $definitionKey === 'reviewContact' ? 'Review contact match' : 'Review address match',
            'taskDefinitionKey' => $definitionKey,
            'processInstanceId' => $processInstanceId,
            'createTime' => '2026-10-28T21:00:00.000Z',
        ];
    }

    private function fakeTask(string $id, string $processInstanceId, string $definitionKey = 'reviewContact'): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'GET'
            ? Http::response($this->task($id, $processInstanceId, $definitionKey))
            : Http::response(null, 204));
    }
}
