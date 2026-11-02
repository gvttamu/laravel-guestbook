<?php

namespace App\Mail;

use App\Models\Customer;
use App\Models\WorkflowInstance;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a credit officer that a loan application in their credit risk tier is waiting for them
 * to decide: the decision engine referred it, or an officer reconsidered it. It names the customer
 * and the tier, and links to the application, but leaves the customer's finances and scores in the app.
 */
class CreditReviewWaiting extends Mailable
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
            subject: "Loan application to review: {$this->customerName()}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.credit-review-waiting',
            with: [
                'customerName' => $this->customerName(),
                'tier' => $this->run->result['credit_risk_tier'] ?? 'unrated',
                'wasReconsidered' => ($this->run->result['reviews'] ?? []) !== [],
                'reviewUrl' => route('credit.reviews.show', $this->run),
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
