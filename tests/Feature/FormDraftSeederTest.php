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
     * @return array<string, array{string, string, int, string}>
     */
    public static function scenarios(): array
    {
        return [
            'sanctioned address' => ['sam.shadow@example.com', 'address_review', 100, 'low'],
            'sanctioned phone' => ['petra.phone@example.com', 'contact_review', 100, 'low'],
            'sanctioned email' => ['blocked@example.com', 'contact_review', 100, 'low'],
            'high score' => ['hannah.high@example.com', 'verified', 100, 'low'],
            'medium score' => ['morgan.medium@example.com', 'verified', 60, 'medium'],
            'low score' => ['lee.low@example.com', 'verified', 30, 'high'],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_each_seeded_draft_ends_in_its_scenario_once_submitted(string $email, string $verificationStatus, int $score, string $riskBand): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::firstWhere('email', 'capturer@example.com'));
        $draft = FormDraft::where('payload->email', $email)->sole();

        $this->post("/customer-drafts/{$draft->id}/submit")->assertRedirect('/inbox');

        $customer = Customer::firstWhere('email', $email);
        $this->assertSame($verificationStatus, $customer->verification->status);
        $this->assertSame('scored', $customer->scoring->status);
        $this->assertSame($score, $customer->scoring->result['score']);
        $this->assertSame($riskBand, $customer->scoring->result['risk_band']);
    }
}
