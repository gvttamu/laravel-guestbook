<?php

namespace Tests\Feature\Jobs;

use App\Jobs\NotifyOwnerOfNewEntry;
use App\Mail\NewGuestbookEntry;
use App\Models\Entry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotifyOwnerOfNewEntryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_emails_the_owner_about_the_entry(): void
    {
        config(['guestbook.owner_email' => 'owner@example.com']);
        $entry = Entry::create(['name' => 'Ada', 'message' => 'Hello']);
        Mail::fake();

        (new NotifyOwnerOfNewEntry($entry))->handle();

        Mail::assertSent(NewGuestbookEntry::class, fn (NewGuestbookEntry $mail) => $mail->hasTo('owner@example.com') && $mail->entry->is($entry));
    }
}
