<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FormDraft;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_are_sent_to_sign_in_from_customer_pages(): void
    {
        $this->get('/customers')->assertRedirect('/login');
        $this->get('/compliance/reviews')->assertRedirect('/login');
    }

    public function test_the_guestbook_stays_public(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_the_sign_in_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
    }

    public function test_users_land_on_their_inbox_after_signing_in(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertRedirect('/inbox');

        $this->assertAuthenticatedAs($user);
        $this->get('/login')->assertRedirect('/inbox');
    }

    public function test_users_sign_in_and_return_to_the_page_they_asked_for(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $this->get('/customers/create');

        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertRedirect('/customers/create');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->from('/login')
            ->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'])
            ->assertRedirect('/login')
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('error', 'These credentials do not match our records.');

        $this->assertGuest();
    }

    public function test_users_can_sign_out(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_pages_receive_the_signed_in_user_and_their_role(): void
    {
        $this->actingAs(User::factory()->complianceOfficer()->create(['name' => 'Grace Hopper']));

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.name', 'Grace Hopper')
            ->where('auth.user.isComplianceOfficer', true)
            ->where('auth.user.canCaptureCustomers', false)
        );

        $this->actingAs(User::factory()->create());

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.isComplianceOfficer', false)
            ->where('auth.user.canCaptureCustomers', true)
        );
    }

    public function test_only_compliance_officers_may_review(): void
    {
        $this->assertTrue(Gate::forUser(User::factory()->complianceOfficer()->make())->allows('review-compliance'));
        $this->assertFalse(Gate::forUser(User::factory()->make())->allows('review-compliance'));
    }

    public function test_review_pages_are_forbidden_to_other_users(): void
    {
        $this->actingAs(User::factory()->create());

        $run = Customer::factory()->create()->workflows()->create(['workflow' => WorkflowInstance::CUSTOMER_VERIFICATION, 'status' => 'contact_review']);

        $this->get('/compliance/reviews')->assertForbidden();
        $this->get("/compliance/reviews/{$run->id}")->assertForbidden();
        $this->post("/compliance/reviews/{$run->id}", ['step' => 'contact', 'decision' => 'approved'])->assertForbidden();
        $this->put("/compliance/reviews/{$run->id}/assignee", ['step' => 'contact', 'assignee_id' => auth()->id()])->assertForbidden();
        $this->assertSame('contact_review', $run->refresh()->status);
        $this->assertNull($run->assigned_to_id);
    }

    public function test_compliance_officers_cannot_list_capture_or_edit_customers(): void
    {
        $officer = User::factory()->complianceOfficer()->create();
        $customer = Customer::factory()->create(['city' => 'London']);
        $draft = FormDraft::factory()->for($officer)->editing($customer, ['city' => 'Paris'])->create();
        $this->actingAs($officer);

        $this->get('/customers')->assertForbidden();
        $this->get("/customers?customer={$customer->id}")->assertForbidden();
        $this->get('/customers/create')->assertForbidden();
        $this->get("/customers/{$customer->id}/edit")->assertForbidden();
        $this->get("/customer-drafts/{$draft->id}/basic")->assertForbidden();
        $this->patchJson("/customer-drafts/{$draft->id}", ['version' => 1, 'current_step' => 'basic'])->assertForbidden();
        $this->post("/customer-drafts/{$draft->id}/submit")->assertForbidden();
        $this->delete("/customer-drafts/{$draft->id}")->assertForbidden();

        $this->assertSame('London', $customer->refresh()->city);
        $this->assertSame(1, FormDraft::count());
    }

    public function test_audit_entries_name_the_signed_in_user(): void
    {
        $editor = User::factory()->create();
        $customer = Customer::factory()->create(['city' => 'London']);
        $draft = FormDraft::factory()->for($editor)->editing($customer, ['city' => 'Paris'])->create();
        $this->actingAs($editor);

        $this->post("/customer-drafts/{$draft->id}/submit")->assertSessionHasNoErrors();

        $this->assertTrue($customer->auditLogs()->sole()->user->is($editor));
    }
}
