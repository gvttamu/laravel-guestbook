<?php

namespace Tests\Feature\Jobs;

use App\Jobs\NotifyOfficerOfScreeningReview;
use App\Jobs\RunVerificationChecks;
use App\Mail\ScreeningReviewWaiting;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotifyOfficerOfScreeningReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_flagged_run_emails_every_compliance_officer_and_nobody_else(): void
    {
        $officers = User::factory()->complianceOfficer()->count(2)->create();
        $capturer = User::factory()->create();
        Mail::fake();
        $run = $this->verificationRun(['phone' => '+1 555 010 0666']);

        RunVerificationChecks::dispatch($run);

        $this->assertSame('contact_review', $run->refresh()->status);
        Mail::assertSent(ScreeningReviewWaiting::class, 2);
        $officers->each(fn (User $officer) => Mail::assertSent(ScreeningReviewWaiting::class, fn (ScreeningReviewWaiting $mail) => $mail->hasTo($officer->email) && $mail->run->is($run)));
        Mail::assertNotSent(ScreeningReviewWaiting::class, fn (ScreeningReviewWaiting $mail) => $mail->hasTo($capturer->email));
    }

    public function test_a_run_that_clears_screening_emails_nobody(): void
    {
        User::factory()->complianceOfficer()->create();
        Mail::fake();

        RunVerificationChecks::dispatch($this->verificationRun());

        Mail::assertNothingSent();
    }

    public function test_the_email_names_the_customer_and_check_and_links_to_the_review_without_the_matched_details(): void
    {
        $run = $this->verificationRun(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone' => '+1 555 010 0666'], 'contact_review');

        $mail = new ScreeningReviewWaiting($run);

        $mail->assertHasSubject('Screening review: Ada Lovelace');
        $mail->assertSeeInHtml('Ada Lovelace');
        $mail->assertSeeInHtml('contact');
        $mail->assertSeeInHtml(route('compliance.reviews.show', $run));
        $mail->assertDontSeeInHtml('555 010 0666');
    }

    public function test_the_email_to_an_assigned_officer_says_who_assigned_the_review(): void
    {
        $run = $this->verificationRun([], 'contact_review');

        $mail = new ScreeningReviewWaiting($run, User::factory()->make(['name' => 'Olive Officer']));

        $mail->assertSeeInHtml('A screening review has been assigned to you');
        $mail->assertSeeInHtml('Olive Officer assigned this review to you');
    }

    public function test_no_email_is_sent_once_the_run_has_moved_on_from_the_step(): void
    {
        $officer = User::factory()->complianceOfficer()->create();
        $run = $this->verificationRun([], 'address_check');
        Mail::fake();

        (new NotifyOfficerOfScreeningReview($run, 'contact', $officer))->handle();
        $run->update(['status' => 'address_review']);
        (new NotifyOfficerOfScreeningReview($run, 'contact', $officer))->handle();

        Mail::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $customerAttributes
     */
    private function verificationRun(array $customerAttributes = [], string $status = 'pending'): WorkflowInstance
    {
        return Customer::factory()->create([
            'phone' => '+44 20 7946 0000',
            'email' => 'ada@example.com',
            'address_line1' => '12 Analytical Row',
            'postal_code' => 'N1 9GU',
            'country' => 'United Kingdom',
            ...$customerAttributes,
        ])->workflows()->create(['workflow' => WorkflowInstance::CUSTOMER_VERIFICATION, 'status' => $status]);
    }
}
