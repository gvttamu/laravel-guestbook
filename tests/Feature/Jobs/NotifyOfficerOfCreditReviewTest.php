<?php

namespace Tests\Feature\Jobs;

use App\Jobs\NotifyOfficerOfCreditReview;
use App\Jobs\ScoreCustomerFinancials;
use App\Mail\CreditReviewWaiting;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotifyOfficerOfCreditReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_referred_application_emails_only_the_credit_officers_of_its_tier(): void
    {
        $medium = User::factory()->creditOfficer('medium')->count(2)->create();
        $highest = User::factory()->creditOfficer('highest')->create();
        $low = User::factory()->creditOfficer('low')->create();
        $compliance = User::factory()->complianceOfficer()->create();
        Mail::fake();
        // Affordable, but unknown to the demo credit bureau (+2 points): referred, medium credit risk.
        $run = $this->scoringRun($this->customer(), 'pending');

        ScoreCustomerFinancials::dispatch($run);

        $run->refresh();
        $this->assertSame('credit_review', $run->status);
        $this->assertSame('medium', $run->result['credit_risk_tier']);
        Mail::assertSent(CreditReviewWaiting::class, 2);
        foreach ($medium as $officer) {
            Mail::assertSent(CreditReviewWaiting::class, fn (CreditReviewWaiting $mail) => $mail->hasTo($officer->email) && $mail->run->is($run));
        }
        foreach ([$low, $highest, $compliance] as $user) {
            Mail::assertNotSent(CreditReviewWaiting::class, fn (CreditReviewWaiting $mail) => $mail->hasTo($user->email));
        }
    }

    public function test_an_application_the_engine_decides_emails_nobody(): void
    {
        User::factory()->creditOfficer('highest')->create();
        Mail::fake();
        $run = $this->scoringRun($this->customer(['monthly_income' => 2000]), 'pending');

        ScoreCustomerFinancials::dispatch($run);

        $this->assertSame('rejected', $run->refresh()->status);
        Mail::assertNothingSent();
    }

    public function test_reconsidering_an_application_emails_the_officers_of_its_tier(): void
    {
        $officer = User::factory()->creditOfficer('high')->create();
        $this->actingAs(User::factory()->creditOfficer('highest')->create());
        Mail::fake();
        $run = $this->scoringRun($this->customer(), 'rejected', ['credit_risk_tier' => 'high']);

        $this->post("/credit/reviews/{$run->id}/reconsider", ['notes' => 'New payslips provided']);

        Mail::assertSent(CreditReviewWaiting::class, fn (CreditReviewWaiting $mail) => $mail->hasTo($officer->email));
        Mail::assertSent(CreditReviewWaiting::class, 1);
    }

    public function test_the_email_names_the_customer_and_tier_and_links_to_the_application_without_their_finances(): void
    {
        $run = $this->scoringRun($this->customer(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'loan_amount' => 12345]), 'credit_review', [
            'credit_risk_tier' => 'high',
            'credit_score' => 642,
        ]);

        $mail = new CreditReviewWaiting($run);

        $mail->assertHasSubject('Loan application to review: Ada Lovelace');
        $mail->assertSeeInHtml('Ada Lovelace');
        $mail->assertSeeInText("It's in the **high** credit risk tier.");
        $mail->assertSeeInText("couldn't approve or reject");
        $mail->assertSeeInHtml(route('credit.reviews.show', $run));
        $mail->assertDontSeeInHtml('12345');
        $mail->assertDontSeeInHtml('642');
    }

    public function test_the_email_for_a_reconsidered_application_says_it_was_reopened(): void
    {
        $run = $this->scoringRun($this->customer(['first_name' => 'Ada', 'last_name' => 'Lovelace']), 'credit_review', [
            'credit_risk_tier' => 'low',
            'reviews' => [['action' => 'reconsidered', 'notes' => 'Look again', 'reviewed_by' => null, 'reviewed_at' => '2026-11-02T09:00:00+00:00']],
        ]);

        (new CreditReviewWaiting($run))->assertSeeInText('has reopened **Ada Lovelace**');
    }

    public function test_no_email_is_sent_once_the_application_is_decided_or_taken_by_another_officer(): void
    {
        $officer = User::factory()->creditOfficer('highest')->create();
        $decided = $this->scoringRun($this->customer(), 'approved', ['credit_risk_tier' => 'low']);
        $taken = $this->scoringRun($this->customer(), 'credit_review', ['credit_risk_tier' => 'low']);
        $taken->assignedTo()->associate(User::factory()->creditOfficer('low')->create())->save();
        Mail::fake();

        (new NotifyOfficerOfCreditReview($decided, $officer))->handle();
        (new NotifyOfficerOfCreditReview($taken, $officer))->handle();

        Mail::assertNothingSent();
    }

    /**
     * A customer whose affordability scores 100, low risk, on a 10,000 home improvement loan.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create([
            'monthly_income' => 6000,
            'other_income' => null,
            'rent_or_mortgage' => 1200,
            'car_payments' => null,
            'other_debt_payments' => null,
            'living_expenses' => 1500,
            'loan_amount' => 10000,
            'loan_term_months' => 60,
            'loan_purpose' => 'home_improvement',
            ...$attributes,
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
            'result' => $status === 'pending' ? null : ['score' => 100, 'risk_band' => 'low', 'reviews' => [], ...$result],
        ]);
    }
}
