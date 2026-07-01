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
     *
     * @throws \Exception When transcription fails
     */
    public function transcribeService(string $audioOrVideoPath, string $processingId): ChurchServiceTranscript;
}
