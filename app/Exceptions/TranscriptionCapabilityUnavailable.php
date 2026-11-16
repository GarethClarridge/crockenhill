<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The configured transcription provider cannot produce the kind of evidence a window asks for
 * (OpenAI cannot align words), whatever the audio. Callers record the window as unavailable and
 * carry on; every other {@see NonRetryableTranscriptionException} still fails.
 */
class TranscriptionCapabilityUnavailable extends NonRetryableTranscriptionException
{
    public function __construct(public readonly string $provider, string $message)
    {
        parent::__construct($message);
    }
}
