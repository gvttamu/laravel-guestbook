<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\CustomerController;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('api:issue-token
    {name : Who the token is for, e.g. "Reporting service"}')]
#[Description('Create an API token that can read customers, for a service such as a reporting tool')]
class IssueApiToken extends Command
{
    /**
     * Each service gets its own user account, so its tokens can be listed and
     * revoked separately and audit entries can name it.
     */
    public function handle(): int
    {
        $name = $this->argument('name');

        $serviceUser = User::firstOrCreate(
            ['email' => Str::slug($name).'@service.invalid'],
            ['name' => $name, 'password' => Str::password(64)],
        );

        $abilities = [CustomerController::READ_ABILITY];

        $token = $serviceUser->createToken($name, $abilities);

        $this->components->info("API token created for {$name} (".implode(', ', $abilities)."). Copy it now; it can't be shown again.");
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
