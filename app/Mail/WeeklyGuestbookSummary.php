<?php

namespace App\Mail;

use App\Models\Entry;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyGuestbookSummary extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  Collection<int, Entry>  $entries
     */
    public function __construct(
        public Collection $entries,
        public CarbonImmutable $weekStart,
        public CarbonImmutable $weekEnd,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Guestbook summary for the week of {$this->weekStart->toFormattedDateString()}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.weekly-guestbook-summary',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => Pdf::loadView('pdf.weekly-guestbook-summary', [
                    'entries' => $this->entries,
                    'weekStart' => $this->weekStart,
                    'weekEnd' => $this->weekEnd,
                ])->setOption('enable_font_subsetting', true)->output(),
                "guestbook-summary-{$this->weekStart->toDateString()}.pdf",
            )->withMime('application/pdf'),
        ];
    }
}
