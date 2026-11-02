<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class FormErrorFlashTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const INERTIA = ['X-Inertia' => 'true'];

    public function test_a_rate_limited_form_submission_goes_back_with_a_message(): void
    {
        $this->signInAttempts(5);

        $this->from('/login')
            ->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'], self::INERTIA)
            ->assertRedirect('/login')
            ->assertInertiaFlash('error', 'Too many attempts. Please wait a minute and try again.');
    }

    public function test_a_rate_limited_request_from_outside_the_app_gets_the_usual_error(): void
    {
        $this->signInAttempts(5);

        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'])
            ->assertTooManyRequests();
    }

    public function test_a_form_submission_that_fails_on_the_server_goes_back_with_a_message(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->post('/failing-form', fn () => throw new RuntimeException('Something broke.'));

        $this->from('/inbox')
            ->post('/failing-form', [], self::INERTIA)
            ->assertRedirect('/inbox')
            ->assertInertiaFlash('error', 'Something went wrong on our side. Please try again.');
    }

    public function test_a_server_error_shows_the_error_page_while_debugging(): void
    {
        config(['app.debug' => true]);
        Route::middleware('web')->post('/failing-form', fn () => throw new RuntimeException('Something broke.'));

        $this->post('/failing-form', [], self::INERTIA)->assertInternalServerError();
    }

    private function signInAttempts(int $attempts): void
    {
        foreach (range(1, $attempts) as $attempt) {
            $this->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong-password']);
        }
    }
}
