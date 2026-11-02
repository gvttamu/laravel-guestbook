<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Workflows\FinancialScoring\CreditRiskTier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('user:create
    {name : The person\'s name}
    {email : The address they sign in with}
    {--compliance : Allow them to review customers flagged by the verification workflow}
    {--credit= : Allow them to decide loan applications the decision engine refers up to this credit risk tier: low, medium, high or highest}
    {--password= : Their password, for when you can\'t answer the prompt (it stays in your shell history)}')]
#[Description('Create a user who can sign in to manage customers')]
class CreateUser extends Command
{
    public function handle(): int
    {
        if ($this->option('compliance') && $this->option('credit')) {
            $this->components->error('A user can be a compliance officer or a credit officer, not both.');

            return self::FAILURE;
        }

        if ($this->option('credit') !== null && ! in_array($this->option('credit'), CreditRiskTier::TIERS, true)) {
            $this->components->error('The credit risk tier must be one of: '.implode(', ', CreditRiskTier::TIERS).'.');

            return self::FAILURE;
        }

        $password = $this->option('password') ?? $this->secret('Password (at least 8 characters)');

        $validator = Validator::make(
            ['name' => $this->argument('name'), 'email' => $this->argument('email'), 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users'],
                'password' => ['required', Password::min(8)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create($validator->validated());

        $role = match (true) {
            $this->option('compliance') => User::ROLE_COMPLIANCE,
            $this->option('credit') !== null => User::ROLE_CREDIT,
            default => null,
        };
        $user->forceFill(['role' => $role, 'credit_risk_tier' => $this->option('credit')])->save();

        $this->components->info("Created {$user->name} <{$user->email}>".match ($role) {
            User::ROLE_COMPLIANCE => ' as a compliance officer.',
            User::ROLE_CREDIT => " as a credit officer for applications up to {$user->credit_risk_tier} credit risk.",
            default => '.',
        });

        return self::SUCCESS;
    }
}
