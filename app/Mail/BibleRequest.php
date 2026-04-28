<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BibleRequest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, string|null>  $data
     */
    public function __construct(public readonly array $data) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Bible Request — '.($this->data['name'] ?? 'Unknown'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.bible-request',
            with: $this->data,
        );
    }
}
