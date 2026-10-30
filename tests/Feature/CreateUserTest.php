<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PASSWORD_PROMPT = 'Password (at least 8 characters)';

    public function test_creates_a_user_who_can_sign_in(): void
    {
        $this->artisan('user:create', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'])
            ->expectsQuestion(self::PASSWORD_PROMPT, 'correct-horse')
            ->expectsOutputToContain('Created Ada Lovelace <ada@example.com>.')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertTrue(Hash::check('correct-horse', $user->password));
        $this->assertFalse($user->isComplianceOfficer());
    }

    public function test_the_compliance_option_makes_them_a_compliance_officer(): void
    {
        $this->artisan('user:create', ['name' => 'Grace Hopper', 'email' => 'grace@example.com', '--compliance' => true])
            ->expectsQuestion(self::PASSWORD_PROMPT, 'correct-horse')
            ->expectsOutputToContain('as a compliance officer.')
            ->assertSuccessful();

        $this->assertTrue(User::sole()->isComplianceOfficer());
    }

    public function test_the_password_can_be_given_as_an_option_instead_of_at_the_prompt(): void
    {
        $this->artisan('user:create', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', '--password' => 'correct-horse'])
            ->doesntExpectOutputToContain(self::PASSWORD_PROMPT)
            ->assertSuccessful();

        $this->assertTrue(Hash::check('correct-horse', User::sole()->password));
    }

    public function test_a_short_password_option_is_rejected(): void
    {
        $this->artisan('user:create', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', '--password' => 'short'])
            ->expectsOutputToContain('The password field must be at least 8 characters.')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_rejects_a_short_password_or_an_email_already_in_use(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('user:create', ['name' => 'Ada Lovelace', 'email' => 'taken@example.com'])
            ->expectsQuestion(self::PASSWORD_PROMPT, 'short')
            ->expectsOutputToContain('The email has already been taken.')
            ->expectsOutputToContain('The password field must be at least 8 characters.')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }
}
