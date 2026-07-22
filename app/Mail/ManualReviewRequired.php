<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\MediaProcessingLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ManualReviewRequired extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, mixed>  $segments
     */
    public function __construct(
        public string $processingId,
        public string $reason,
        public array $segments = []
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Manual Review Required - Sermon Processing '.$this->processingId,
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
                'reviewUrl' => $this->reviewUrl(),
            ]
        );
    }

    private function reviewUrl(): string
    {
        $log = MediaProcessingLog::query()->where('processing_id', $this->processingId)->first();

        if ($log !== null) {
            return route('admin.recordings.sermon-segment', $log->processing_id);
        }

        return route('admin.services.index');
    }
}
