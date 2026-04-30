<?php

declare(strict_types=1);

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

    /**
     * @param  array<string, mixed>|null  $processingMetadata
     */
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
            $this->stackTrace = $this->sanitiseStackTrace($exception->getTraceAsString());
            $this->file = str_replace(base_path().'/', '', $exception->getFile());
            $this->line = $exception->getLine();
        } else {
            $this->errorMessage = $exception;
            $this->stackTrace = 'Stack trace not available (error provided as string)';
            $this->file = 'Unknown';
            $this->line = 'Unknown';
        }
    }

    /**
     * Sanitise a stack trace before storing or emailing it.
     * Strips server base paths and redacts credential-like patterns.
     */
    private function sanitiseStackTrace(string $trace): string
    {
        // Strip absolute server paths to avoid leaking server structure
        $trace = str_replace(base_path().'/', '', $trace);

        // Redact values that look like credentials (password=, secret=, key=, token=)
        $trace = preg_replace('/\b(password|secret|token|api_key|apikey|auth)[=:]\S+/i', '$1=[REDACTED]', $trace) ?? $trace;

        // Truncate to a safe length
        return mb_substr($trace, 0, 3000);
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
