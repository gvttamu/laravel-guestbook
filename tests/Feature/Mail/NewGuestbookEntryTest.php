<?php

namespace Tests\Feature\Mail;

use App\Mail\NewGuestbookEntry;
use App\Models\Entry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class NewGuestbookEntryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_renders_the_signer_and_their_message(): void
    {
        $entry = Entry::create(['name' => 'Ada', 'message' => 'Lovely site']);

        $mail = new NewGuestbookEntry($entry);

        $mail->assertHasSubject('Ada signed the guestbook');
        $mail->assertSeeInText('Ada');
        $mail->assertSeeInText('Lovely site');
    }

    public function test_escapes_html_in_the_message(): void
    {
        $entry = Entry::create(['name' => 'Mallory', 'message' => '<script>alert(1)</script>']);

        $mail = new NewGuestbookEntry($entry);

        $mail->assertDontSeeInHtml('<script>alert(1)</script>', false);
    }
}
