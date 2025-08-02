<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DiskSpaceWarning extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $processingId
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Critical: Disk Space Error - Livestream Processing',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.disk-space-warning',
            with: [
                'processingId' => $this->processingId,
            ]
        );
    }
}