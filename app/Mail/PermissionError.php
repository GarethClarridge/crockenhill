<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PermissionError extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $processingId,
        public string $operation
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'File Permission Error - Livestream Processing',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.permission-error',
            with: [
                'processingId' => $this->processingId,
                'operation' => $this->operation,
            ]
        );
    }
}
