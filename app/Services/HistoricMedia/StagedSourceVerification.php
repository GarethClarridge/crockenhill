<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Storage;

/**
 * Whether a historic run's staged source is the recording its transcript and section timings
 * were derived from.
 *
 * A re-run that re-cuts media from a different encode shifts every cut against timings that
 * describe the original, so the staged bytes must hash to the run's recorded hash. A
 * concatenated source restaged with another ffmpeg differs in container bytes while keeping
 * the timeline (run 950), so a concatenation is checked against the hash the concatenation
 * gate stamped when it rebuilt the source ({@see ConcatenatedSourceRestage}); until then it is
 * refused, which is the safe direction.
 *
 * Call inside the run's staging context: the staging guard re-roots `temp_disk` at the batch.
 */
final class StagedSourceVerification
{
    private const UNREBUILT_CONCATENATION = 'concatenated source has not been rebuilt through historic-import:restage-concatenated-source';

    public function refusal(MediaProcessingLog $run): ?string
    {
        $sourcePath = $run->source_file_path;
        $disk = Storage::disk((string) config('media-processing.storage.temp_disk'));
        $unrebuiltConcatenation = $run->concatenatedSourceRestage() === null
            && data_get($run->processing_metadata?->toArray(), 'historic_import.concatenation') === 'lossless';

        if (! is_string($sourcePath) || $sourcePath === '' || ! $disk->exists($sourcePath)) {
            return $unrebuiltConcatenation ? self::UNREBUILT_CONCATENATION : 'staged source is missing';
        }

        $expectedHash = $run->stagedSourceFileHash();

        if ($expectedHash === null) {
            return $unrebuiltConcatenation ? self::UNREBUILT_CONCATENATION : 'run has no recorded source hash';
        }

        $stream = $disk->readStream($sourcePath);

        if ($stream === null) {
            return 'staged source could not be read';
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $actualHash = hash_final($hash);
        } finally {
            fclose($stream);
        }

        if (! hash_equals($expectedHash, $actualHash)) {
            return 'staged source hash does not match recorded evidence';
        }

        return null;
    }
}
