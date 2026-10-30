<?php

namespace Tests\Feature\Mail;

use App\Mail\WeeklyGuestbookSummary;
use App\Models\Entry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WeeklyGuestbookSummaryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_summarises_the_week_and_attaches_a_pdf(): void
    {
        $entries = new Collection([
            Entry::create(['name' => 'Ada', 'message' => 'Hello']),
            Entry::create(['name' => 'Grace', 'message' => 'Hi']),
        ]);

        $mail = $this->makeSummary($entries);

        $mail->assertHasSubject('Guestbook summary for the week of Oct 19, 2026');
        $mail->assertSeeInText('2 entries');
        $attachment = $mail->attachments()[0];
        $this->assertSame('guestbook-summary-2026-10-19.pdf', $attachment->as);
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertStringStartsWith('%PDF-', $attachment->attachWith(fn () => null, fn ($data) => $data()));
    }

    public function test_says_when_no_one_signed(): void
    {
        $mail = $this->makeSummary(new Collection);

        $mail->assertSeeInText('no entries');
    }

    public function test_pdf_lists_entries_with_html_escaped(): void
    {
        $entries = new Collection([
            Entry::create(['name' => 'Mallory', 'message' => '<script>alert(1)</script>']),
        ]);

        $html = view('pdf.weekly-guestbook-summary', [
            'entries' => $entries,
            'weekStart' => CarbonImmutable::parse('2026-10-19'),
            'weekEnd' => CarbonImmutable::parse('2026-10-25 23:59:59'),
        ])->render();

        $this->assertStringContainsString('Mallory', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /**
     * @param  Collection<int, Entry>  $entries
     */
    private function makeSummary(Collection $entries): WeeklyGuestbookSummary
    {
        return new WeeklyGuestbookSummary(
            $entries,
            CarbonImmutable::parse('2026-10-19'),
            CarbonImmutable::parse('2026-10-25 23:59:59'),
        );
    }
}
