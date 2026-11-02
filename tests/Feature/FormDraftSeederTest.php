<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FormDraft;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FormDraftSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array<string, array{string, string, int, string, string, string}>
     */
    public static function scenarios(): array
    {
        return [
            'sanctioned address' => ['sam.shadow@example.com', 'address_review', 100, 'low', 'approved', 'low'],
            'sanctioned phone' => ['petra.phone@example.com', 'contact_review', 100, 'low', 'approved', 'low'],
            'sanctioned email' => ['blocked@example.com', 'contact_review', 100, 'low', 'approved', 'low'],
            'high score' => ['hannah.high@example.com', 'verified', 100, 'low', 'approved', 'low'],
            'medium score' => ['morgan.medium@example.com', 'verified', 60, 'medium', 'credit_review', 'low'],
            'low score' => ['lee.low@example.com', 'verified', 30, 'high', 'rejected', 'medium'],
            'poor credit' => ['rita.risky@example.com', 'verified', 100, 'low', 'rejected', 'medium'],
            'fair credit' => ['fiona.fair@example.com', 'verified', 100, 'low', 'credit_review', 'low'],
            'no credit file' => ['nina.newcomer@example.com', 'verified', 100, 'low', 'credit_review', 'medium'],
            'large loan' => ['lars.large@example.com', 'verified', 85, 'low', 'credit_review', 'high'],
            'business loan' => ['bianca.business@example.com', 'verified', 100, 'low', 'credit_review', 'highest'],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_each_seeded_draft_ends_in_its_scenario_once_submitted(string $email, string $verificationStatus, int $score, string $riskBand, string $scoringStatus, string $creditRiskTier): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::firstWhere('email', 'capturer@example.com'));
        $draft = FormDraft::where('payload->email', $email)->sole();

        $this->post("/customer-drafts/{$draft->id}/submit")->assertRedirect('/inbox');

        $customer = Customer::firstWhere('email', $email);
        $this->assertSame($verificationStatus, $customer->verification->status);
        $this->assertSame($scoringStatus, $customer->scoring->status);
        $this->assertSame($score, $customer->scoring->result['score']);
        $this->assertSame($riskBand, $customer->scoring->result['risk_band']);
        $this->assertSame($creditRiskTier, $customer->scoring->result['credit_risk_tier']);
    }
}
