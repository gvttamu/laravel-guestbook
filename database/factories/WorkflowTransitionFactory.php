<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTransition;
use App\Workflows\FinancialScoring\FinancialScoring;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowTransition>
 */
class WorkflowTransitionFactory extends Factory
{
    /**
     * Define the model's default state: a new customer's financial scoring run being scored.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_instance_id' => fn (): int => Customer::factory()->create()->workflows()->create([
                'workflow' => WorkflowInstance::FINANCIAL_SCORING,
                'status' => 'scored',
            ])->id,
            'transition' => FinancialScoring::SCORE,
            'from' => 'pending',
            'to' => 'scored',
            'user_id' => null,
            'reconstructed' => false,
            'created_at' => now(),
        ];
    }

    /**
     * Indicate that the transition was rebuilt from the audit log.
     */
    public function reconstructed(): static
    {
        return $this->state(fn (array $attributes) => ['reconstructed' => true]);
    }
}
