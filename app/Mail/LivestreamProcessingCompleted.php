<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LivestreamProcessingCompleted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $processingId,
        public array $summary = []
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Livestream Processing Completed - ' . $this->processingId,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.livestream-processing-completed',
            with: [
                'processingId' => $this->processingId,
                'summary' => $this->summary,
            ]
        );
    }
}