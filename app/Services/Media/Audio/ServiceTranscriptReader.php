<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Reads the full-service transcript a run banked during transcription.
 *
 * The disk depends on where the transcription stage wrote it: a
 * `service-transcripts/` key belongs to the transcript disk, and anything else
 * to the run's temporary disk. Both the sermon-transcript job and the span
 * repair need that rule, and a run whose staging batch is active resolves its
 * key only while that context is open — so this is one shared rule rather than
 * a copy in each caller.
 */
class ServiceTranscriptReader
{
    /**
     * @throws RuntimeException When no transcript is recorded, or the recorded one is gone.
     */
    public function read(MediaProcessingLog $processingLog): ChurchServiceTranscript
    {
        $transcriptPath = $processingLog->serviceTranscriptPath();

        if ($transcriptPath === null) {
            throw new RuntimeException('No full-service transcript recorded for this run.');
        }

        $disk = $this->diskFor($transcriptPath);

        if (! Storage::disk($disk)->exists($transcriptPath)) {
            throw new RuntimeException('The recorded full-service transcript is unavailable.');
        }

        /** @var array<string, mixed> $transcriptData */
        $transcriptData = json_decode(
            (string) Storage::disk($disk)->get($transcriptPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return ChurchServiceTranscript::fromArray($transcriptData);
    }

    /**
     * The transcript, or null when the evidence is missing or unreadable.
     *
     * A repair reports an unreadable transcript as unresolved rather than
     * failing the whole selection, so it asks for the evidence this way.
     */
    public function tryRead(MediaProcessingLog $processingLog): ?ChurchServiceTranscript
    {
        try {
            return $this->read($processingLog);
        } catch (\Throwable) {
            return null;
        }
    }

    private function diskFor(string $transcriptPath): string
    {
        return str_starts_with($transcriptPath, 'service-transcripts/')
            ? (string) config('media-processing.storage.transcript_disk', 'local')
            : (string) config('media-processing.storage.temp_disk', 'local');
    }
}
