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
        public \Throwable|string $exception,
        public string $step = 'unknown',
        public ?array $processingMetadata = null
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
        $data = [
            'processingId' => $this->processingId,
            'step' => $this->step,
            'metadata' => $this->processingMetadata,
        ];

        if ($this->exception instanceof \Throwable) {
            $data['errorMessage'] = $this->exception->getMessage();
            $data['stackTrace'] = $this->exception->getTraceAsString();
            $data['file'] = $this->exception->getFile();
            $data['line'] = $this->exception->getLine();
        } else {
            $data['errorMessage'] = $this->exception;
            $data['stackTrace'] = 'Stack trace not available (error provided as string)';
            $data['file'] = 'Unknown';
            $data['line'] = 'Unknown';
        }

        return new Content(
            markdown: 'emails.livestream-processing-failed',
            with: $data
        );
    }
}