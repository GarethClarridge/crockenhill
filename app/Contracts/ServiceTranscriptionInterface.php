<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ChurchServiceTranscript;

interface ServiceTranscriptionInterface
{
    /**
     * Produce a timestamped transcript of an entire service recording.
     *
     * @param  string  $audioOrVideoPath  Absolute path to the recording (audio or video)
     * @param  string  $processingId  Processing ID for logging
     * @param  string|null  $prompt  Priming text; null uses the configured full-service prompt,
     *                               and an empty string asks for no priming at all. An isolated
     *                               window passes '' because whole-service priming makes the
     *                               model emit service-shaped text over music and silence.
     *
     * @throws \Exception When transcription fails
     */
    public function transcribeService(string $audioOrVideoPath, string $processingId, ?string $prompt = null): ChurchServiceTranscript;
}
