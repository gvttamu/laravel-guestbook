<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('user:create
    {name : The person\'s name}
    {email : The address they sign in with}
    {--compliance : Allow them to review customers flagged by the verification workflow}
    {--password= : Their password, for when you can\'t answer the prompt (it stays in your shell history)}')]
#[Description('Create a user who can sign in to manage customers')]
class CreateUser extends Command
{
    public function handle(): int
    {
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

        if ($this->option('compliance')) {
            $user->forceFill(['role' => User::ROLE_COMPLIANCE])->save();
        }

        $this->components->info("Created {$user->name} <{$user->email}>".($user->isComplianceOfficer() ? ' as a compliance officer.' : '.'));

        return self::SUCCESS;
    }
}
