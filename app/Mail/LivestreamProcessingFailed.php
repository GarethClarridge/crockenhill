<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LivestreamProcessingFailed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $processingId,
        public \Throwable $exception,
        public string $step = 'unknown'
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Livestream Processing Failed - ' . $this->processingId,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.livestream-processing-failed',
            with: [
                'processingId' => $this->processingId,
                'step' => $this->step,
                'errorMessage' => $this->exception->getMessage(),
                'stackTrace' => $this->exception->getTraceAsString(),
                'file' => $this->exception->getFile(),
                'line' => $this->exception->getLine(),
            ]
        );
    }
}