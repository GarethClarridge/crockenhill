<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ManualReviewRequired extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $processingId,
        public string $reason,
        public array $segments = []
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Manual Review Required - Livestream Processing ' . $this->processingId,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.manual-review-required',
            with: [
                'processingId' => $this->processingId,
                'reason' => $this->reason,
                'segments' => $this->segments,
                'segmentCount' => count($this->segments),
            ]
        );
    }
}