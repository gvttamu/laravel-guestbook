<?php

namespace App\Mail;

use App\Models\Customer;
use App\Models\WorkflowInstance;
use App\Workflows\CustomerVerification\CustomerVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a compliance officer that a customer's sanctions screening found a possible match
 * for them to review. It names the customer and the check, and links to the review, but
 * leaves the matched details (phone numbers, addresses) in the app.
 */
class ScreeningReviewWaiting extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public WorkflowInstance $run) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Screening review: {$this->customerName()}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.screening-review-waiting',
            with: [
                'customerName' => $this->customerName(),
                'step' => CustomerVerification::REVIEW_PLACES[$this->run->status],
                'reviewUrl' => route('compliance.reviews.show', $this->run),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    private function customerName(): string
    {
        /** @var Customer $customer */
        $customer = $this->run->subject;

        return "{$customer->first_name} {$customer->last_name}";
    }
}
