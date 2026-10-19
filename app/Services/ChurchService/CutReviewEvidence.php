<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Support\Arr;
use RuntimeException;

/** A review describes the recorded output, never a raw section or a newly computed cut. */
class CutReviewEvidence
{
    /** @return array<string, mixed> */
    public function recorded(MediaProcessingLog $log, ?int $sectionId = null): array
    {
        $metadata = $log->processing_metadata?->toArray() ?? [];
        $section = $sectionId === null ? null : $log->serviceSections()->findOrFail($sectionId);
        $key = $sectionId === null ? 'sermon' : 'section_'.$sectionId;
        $media = $metadata['media_outputs'][$key] ?? null;
        if (! is_array($media) || ! is_string($media['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $media['sha256']) !== 1
            || ! is_string($media['disk'] ?? null) || ! is_string($media['path'] ?? null) || ! is_int($media['size'] ?? null)
            || ($media['provenance']['processing_id'] ?? null) !== $log->processing_id) {
            throw new RuntimeException('Review requires a recorded output bound to this processing run.');
        }
        $extraction = $section?->metadata?->raw['publication_candidate_extraction'] ?? null;
        if ($section !== null && (! is_array($extraction) || ($extraction['processing_id'] ?? null) !== $log->processing_id
            || ($extraction['media_signature'] ?? null) !== $this->sectionSignature($section, $extraction))) {
            throw new RuntimeException('The section no longer describes its recorded cut.');
        }
        $raw = $section === null ? ($media['provenance']['segments'] ?? null) : ($extraction['segments'] ?? null);
        if (! is_array($raw) || $raw === []) {
            throw new RuntimeException('Review requires the executed cut segments.');
        }
        $segments = [];
        foreach ($raw as $span) {
            if (! is_array($span) || ! is_numeric($span['start_time'] ?? null) || ! is_numeric($span['end_time'] ?? null)
                || ! is_finite((float) $span['start_time']) || ! is_finite((float) $span['end_time'])
                || $span['start_time'] < 0 || $span['end_time'] <= $span['start_time']) {
                throw new RuntimeException('Invalid executed cut segments.');
            }
            $segments[] = ['start_time' => (float) $span['start_time'], 'end_time' => (float) $span['end_time']];
        }
        $evidence = [
            'run' => $log->id, 'processing_id' => $log->processing_id,
            'source' => ['path' => $log->source_file_path, 'sha256' => $log->stagedSourceFileHash()],
            'kind' => 'recorded_output',
            'section' => $section === null ? null : ['id' => $section->id, 'type' => $section->section_type->value, 'title' => $section->title],
            'segments' => $segments,
            'media' => ['disk' => $media['disk'], 'path' => $media['path'], 'size' => $media['size'], 'sha256' => $media['sha256']],
        ];

        return [...$evidence, 'evidence_sha256' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))];
    }

    /** @param array<string, mixed> $extraction */
    private function sectionSignature(ServiceSection $section, array $extraction): string
    {
        $payload = $section->mediaSignaturePayload();
        $recorded = $extraction['media_processing'] ?? null;
        if (is_array($recorded) && is_int($recorded['version'] ?? null)) {
            $settings = $payload['media_processing'];
            $settings['version'] = $recorded['version'];
            if (Arr::sortRecursive($settings) !== Arr::sortRecursive($recorded)) {
                throw new RuntimeException('The recorded media settings cannot be reconciled for this review.');
            }
            $payload['media_processing'] = $settings;
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
