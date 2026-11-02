<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Support\Collection;

/**
 * A credit review is a financial scoring run waiting for a credit officer: the decision
 * engine referred the loan application, or an officer reconsidered its decision.
 */
trait ListsCreditReviews
{
    /**
     * The referred applications the officer can pick up, in their credit risk tier or a less risky one and unassigned
     * or assigned to them, riskiest (most credit risk points) first, then longest waiting, each with
     * its customer and loan.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function openCreditReviews(User $officer): Collection
    {
        return WorkflowInstance::awaitingCreditReviewBy($officer)
            ->with('subject:id,first_name,last_name,email,loan_amount,loan_term_months')
            ->get()
            ->map(fn (WorkflowInstance $run): array => [
                ...$this->creditScores($run),
                'customer' => $run->subject->only(['id', 'first_name', 'last_name', 'email']),
                'loan_amount' => $run->subject->loan_amount,
                'loan_term_months' => $run->subject->loan_term_months,
            ])
            ->sortBy([
                fn (array $a, array $b): int => ($b['credit_risk_points'] ?? 0) <=> ($a['credit_risk_points'] ?? 0),
                fn (array $a, array $b): int => strtotime($a['referred_at']) <=> strtotime($b['referred_at']),
            ])
            ->values();
    }

    /**
     * The run's scores and the engine's decision on them.
     *
     * @return array{id: int, referred_at: string, score: ?int, risk_band: ?string, credit_score: ?int, credit_bureau: ?string, engine_decision: ?string, engine_reasons: list<string>, credit_risk_tier: ?string, credit_risk_points: ?int, credit_risk_reasons: list<string>}
     */
    private function creditScores(WorkflowInstance $run): array
    {
        return [
            'id' => $run->id,
            'referred_at' => $run->result['referred_at'] ?? $run->updated_at->toIso8601String(),
            'score' => $run->result['score'] ?? null,
            'risk_band' => $run->result['risk_band'] ?? null,
            'credit_score' => $run->result['credit_score'] ?? null,
            'credit_bureau' => $run->result['credit_bureau'] ?? null,
            'engine_decision' => $run->result['engine_decision'] ?? null,
            'engine_reasons' => $run->result['engine_reasons'] ?? [],
            'credit_risk_tier' => $run->result['credit_risk_tier'] ?? null,
            'credit_risk_points' => $run->result['credit_risk_points'] ?? null,
            'credit_risk_reasons' => $run->result['credit_risk_reasons'] ?? [],
        ];
    }

    /**
     * The credit officers' decisions and reconsiderations of the run, oldest first, naming each officer.
     *
     * @return list<array{action: string, notes: ?string, reviewed_by: ?string, reviewed_at: string}>
     */
    private function creditDecisions(WorkflowInstance $run): array
    {
        $reviews = $run->result['reviews'] ?? [];
        $officers = User::whereIn('id', array_column($reviews, 'reviewed_by'))->pluck('name', 'id');

        return array_map(fn (array $review): array => [
            'action' => $review['action'],
            'notes' => $review['notes'] ?? null,
            'reviewed_by' => $officers->get($review['reviewed_by']),
            'reviewed_at' => $review['reviewed_at'],
        ], $reviews);
    }

    /**
     * The customer's name, contact details, financials and loan, which the officer decides on.
     *
     * @return array<string, mixed>
     */
    private function applicant(Customer $customer): array
    {
        return $customer->only(['id', 'first_name', 'last_name', 'email', 'phone', ...Customer::FINANCIAL_FIELDS, ...Customer::LOAN_FIELDS]);
    }
}
