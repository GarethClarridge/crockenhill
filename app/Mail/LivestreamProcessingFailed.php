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

    public string $errorMessage;

    public string $stackTrace;

    public string $file;

    public string|int $line;

    public function __construct(
        public string $processingId,
        \Throwable|string $exception,
        public string $step = 'unknown',
        public ?array $processingMetadata = null
    ) {
        // Extract only serializable scalar data from the exception immediately,
        // so the mailable can be safely queued without closure serialization issues.
        if ($exception instanceof \Throwable) {
            $this->errorMessage = $exception->getMessage();
            $this->stackTrace = $exception->getTraceAsString();
            $this->file = $exception->getFile();
            $this->line = $exception->getLine();
        } else {
            $this->errorMessage = $exception;
            $this->stackTrace = 'Stack trace not available (error provided as string)';
            $this->file = 'Unknown';
            $this->line = 'Unknown';
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Livestream Processing Failed - '.$this->processingId,
        );
    }

    public function content(): Content
    {
        $data = [
            'processingId' => $this->processingId,
            'step' => $this->step,
            'metadata' => $this->processingMetadata,
            'errorMessage' => $this->errorMessage,
            'stackTrace' => $this->stackTrace,
            'file' => $this->file,
            'line' => $this->line,
        ];

        return new Content(
            markdown: 'emails.livestream-processing-failed',
            with: $data
        );
    }
}
