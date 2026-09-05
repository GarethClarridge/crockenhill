<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Whether a run's durable artifacts can stand in for redoing the work.
 *
 * A retry resumes a run from the step it failed at, but the steps before that
 * point are re-executed from their inputs rather than from their outputs. For
 * the cheap steps that costs nothing. For the two expensive ones it is most of
 * the bill: on the 2026-09-05 recovery, 171 of 389 failed runs resume at or
 * near the start of the chain, and re-running transcription and RMS generation
 * for them is roughly ten of the sixteen hours.
 *
 * Reuse is consistent with how a reset already behaves.
 * {@see ProcessingPhaseResetService::resetAnalyzeSegments()} clears `rms_stats`,
 * the segments and the sermon bounds — everything *derived* from the RMS log —
 * while deliberately leaving `rms_log_path` alone. The artifact is already
 * treated as durable evidence about an unchanged recording; this only stops the
 * pipeline paying to produce it twice.
 *
 * Forcing regeneration therefore needs no flag: clear the path column and the
 * artifact is gone as far as this class is concerned. That keeps a deliberate
 * re-transcription with a better model working exactly as before, and avoids a
 * configuration seam whose only reader would be this file.
 *
 * Why validity is checked rather than existence alone: these artifacts live on
 * the staging volume, and the event that makes reuse worth having — a host
 * losing power mid-write — is also the event that leaves truncated and
 * zero-length files behind. Adopting one of those would launder a destroyed
 * artifact into a confident empty result, which is worse than re-running.
 */
final class ProcessingArtifactReuse
{
    public function __construct(
        private readonly RmsAnalysisService $rmsAnalysis,
    ) {}

    /**
     * True when the run's recorded RMS log exists and still parses to frames.
     *
     * An entirely-silent log is a legitimate outcome the segmentation service
     * handles explicitly, so it is judged on whether frames were recovered at
     * all, not on whether any of them contain speech.
     */
    public function rmsLogIsUsable(MediaProcessingLog $processingLog): bool
    {
        $path = $processingLog->rms_log_path;

        if (! is_string($path) || $path === '') {
            return false;
        }

        $contents = $this->contents($path);

        if ($contents === null) {
            return false;
        }

        try {
            return $this->rmsAnalysis->extractRmsData($contents) !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * True when the run's stored full-service transcript exists and holds cues.
     *
     * A transcript with no cues cannot be distinguished from a truncated write,
     * and a service recording that genuinely produced no speech at all fails
     * later steps on its own terms rather than being carried forward silently.
     */
    public function serviceTranscriptIsUsable(MediaProcessingLog $processingLog): bool
    {
        $path = $processingLog->serviceTranscriptPath();

        if (! is_string($path) || $path === '') {
            return false;
        }

        $contents = $this->contents($path);

        if ($contents === null) {
            return false;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        return is_array($decoded)
            && is_array($decoded['cues'] ?? null)
            && $decoded['cues'] !== [];
    }

    /**
     * The artifact's bytes, or null when it cannot be read at all.
     *
     * A read that throws is treated the same as a missing file: an unreachable
     * volume must produce "cannot reuse", never a partial string.
     */
    private function contents(string $path): ?string
    {
        try {
            $disk = Storage::disk(ServiceArtifactDisk::for($path));

            if (! $disk->exists($path)) {
                return null;
            }

            $contents = $disk->get($path);
        } catch (Throwable) {
            return null;
        }

        return is_string($contents) && trim($contents) !== '' ? $contents : null;
    }
}
