<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SendWeeklySummary;
use App\Mail\WeeklyGuestbookSummary;
use App\Models\Entry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendWeeklySummaryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_emails_the_owner_only_last_weeks_entries(): void
    {
        config(['guestbook.owner_email' => 'owner@example.com']);
        $this->travelTo('2026-10-18 23:59:59');
        Entry::create(['name' => 'Too early', 'message' => 'Sunday before']);
        $this->travelTo('2026-10-19 00:00:00');
        $monday = Entry::create(['name' => 'Ada', 'message' => 'Monday']);
        $this->travelTo('2026-10-25 23:59:59');
        $sunday = Entry::create(['name' => 'Grace', 'message' => 'Sunday']);
        $this->travelTo('2026-10-26 00:00:00');
        Entry::create(['name' => 'Too late', 'message' => 'This week']);
        $this->travelTo('2026-10-26 08:00:00');
        Mail::fake();

        (new SendWeeklySummary)->handle();

        Mail::assertSent(WeeklyGuestbookSummary::class, fn (WeeklyGuestbookSummary $mail) => $mail->hasTo('owner@example.com')
            && $mail->entries->modelKeys() === [$monday->id, $sunday->id]
            && $mail->weekStart->toDateString() === '2026-10-19'
            && $mail->weekEnd->toDateString() === '2026-10-25');
    }

    public function test_does_not_email_anyone_without_an_owner_email(): void
    {
        config(['guestbook.owner_email' => null]);
        Mail::fake();

        (new SendWeeklySummary)->handle();

        Mail::assertNothingSent();
    }

    public function test_is_scheduled_for_monday_mornings(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => $event->description === SendWeeklySummary::class);

        $this->assertCount(1, $events);
        $this->assertSame('0 8 * * 1', $events->first()->expression);
    }
}
