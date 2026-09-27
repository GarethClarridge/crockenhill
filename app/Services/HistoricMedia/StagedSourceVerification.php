<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Storage;

/**
 * Whether a historic run's staged source is still the recording its transcript and section
 * timings were derived from.
 *
 * A re-run that re-cuts media from a different encode shifts every cut against timings that
 * describe the original. Every way a source reaches staging proves it there: the importer
 * records the size and hash of the bytes it processed, `historic-import:restage-source` refuses
 * a file that does not hash to them, and a concatenation is rebuilt only through
 * {@see ConcatenatedSourceRestage}, which proves its parts and timeline. Nothing else writes a
 * staged source, so what remains to catch is a file replaced or truncated since, and its exact
 * size does that without reading it. Hashing here re-read up to 11 GB a run, twice a batch, on
 * the drive the cuts read from (canary 6, 2026-09-27).
 *
 * A lossless concatenation must carry the gate's stamp: an original join nobody rebuilt was
 * never proven, so it is refused, which is the safe direction.
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

        $restage = $run->concatenatedSourceRestage();

        if ($restage === null
            && data_get($run->processing_metadata?->toArray(), 'historic_import.concatenation') === 'lossless') {
            return self::UNREBUILT_CONCATENATION;
        }

        if (! is_string($sourcePath) || $sourcePath === '' || ! $disk->exists($sourcePath)) {
            return 'staged source is missing';
        }

        // A rebuilt join is the size the gate stamped. Stamps from before the gate recorded one
        // fall back to the run's: all nine rebuilt joins matched it (census, 2026-09-27).
        $recordedSize = $restage['size'] ?? $run->file_size;

        if (! is_int($recordedSize) || $recordedSize <= 0) {
            return 'run has no recorded source size';
        }

        $size = $disk->size($sourcePath);

        if ($size !== $recordedSize) {
            return sprintf('staged source is not the recorded size (%d bytes, recorded %d)', $size, $recordedSize);
        }

        return null;
    }
}
