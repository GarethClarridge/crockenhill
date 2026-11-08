<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ChurchServiceTranscript;

interface ServiceTranscriptionInterface
{
    /**
     * Decode an isolated output-edge window without changing full-service evidence.
     * Times are relative to the window. Empty means a successful decode found no words.
     *
     * Aligned, each word also carries `aligned_end`: where the decoder's cross-attention places the
     * word's end (whisper.cpp DTW). The decoder's own times stretch a word back over music before
     * it; the aligned end does not (RA3, video plan §4.1d, 11-08). A provider that cannot align
     * refuses rather than returning unaligned words.
     *
     * @return list<array{start: float, end: float, word: string, aligned_end?: float}>
     */
    public function transcribeEdgeWindow(string $audioPath, bool $aligned = false): array;

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
