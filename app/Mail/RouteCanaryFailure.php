<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RouteCanaryFailure extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, string>  $failures  URL => human-readable reason
     */
    public function __construct(public array $failures) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Route canary failure — '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.route-canary-failure',
            with: [
                'failures' => $this->failures,
            ],
        );
    }
}
