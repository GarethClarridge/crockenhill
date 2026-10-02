<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Fail closed when a banked ensemble is incomplete or has unresolved claims. */
class EnsembleReviewGate
{
    public function requiresReview(MediaProcessingLog $log): bool
    {
        $bank = $log->processing_metadata?->raw['service_structure_ensemble'] ?? null;

        if (! is_array($bank) || $bank === []) {
            return false;
        }

        try {
            return $this->bankRequiresReview($log, $bank);
        } catch (Throwable) {
            return true;
        }
    }

    /** @param  array<int, mixed>  $bank */
    private function bankRequiresReview(MediaProcessingLog $log, array $bank): bool
    {

        $latest = end($bank);

        if (! is_array($latest) || ! is_array($latest['composition'] ?? null)) {
            return true;
        }

        $composition = $latest['composition'];

        if (($composition['validation_passed'] ?? null) !== true
            || (($composition['degraded'] ?? null) !== false && ($composition['degraded_reviewed'] ?? null) !== true)
            || ! empty($composition['disputes'] ?? [])) {
            return true;
        }

        $diskName = $latest['artifact_disk'] ?? null;
        $inputPath = $latest['input_path'] ?? null;
        $hash = $latest['input_hash'] ?? null;

        if (! is_string($diskName) || ! is_string($inputPath) || ! is_string($hash)) {
            return true;
        }

        $disk = Storage::disk($diskName);
        $input = $disk->get($inputPath);

        if (! is_string($input) || hash('sha256', $input) !== $hash) {
            return true;
        }

        $snapshot = json_decode($input, true);

        if (! is_array($snapshot) || ! $this->snapshotMatchesCurrent($log, $snapshot)) {
            return true;
        }

        $seenSlots = [];

        foreach ($latest['outcomes'] ?? [] as $slot) {
            if (! is_array($slot) || ! in_array($slot['status'] ?? null, ['valid', 'invalid', 'interrupted', 'unavailable'], true)
                || ! is_int($slot['slot'] ?? null) || ! in_array($slot['slot'], [0, 1, 2, 3], true)
                || in_array($slot['slot'], $seenSlots, true)
                || ! is_string($slot['path'] ?? null)
                || ! str_starts_with($slot['path'], ServiceArtifactDisk::DURABLE_PREFIX)
                || ! is_string($slot['sha256'] ?? null)
                || ($latest['slots'][$slot['slot']]['sha256'] ?? null) !== $slot['sha256']
                || ($latest['slots'][$slot['slot']]['path'] ?? null) !== $slot['path']
                || ($latest['slots'][$slot['slot']]['model'] ?? null) !== ($slot['model'] ?? null)) {
                return true;
            }

            $seenSlots[] = $slot['slot'];

            $rawSlot = $disk->get($slot['path']);

            if (! is_string($rawSlot) || hash('sha256', $rawSlot) !== $slot['sha256']) {
                return true;
            }

            $recorded = json_decode($rawSlot, true);

            if (! is_array($recorded) || ($recorded['status'] ?? null) !== $slot['status']
                || ($recorded['input_hash'] ?? null) !== $hash
                || ($recorded['artifact_disk'] ?? null) !== ServiceStructureEnsembleReplay::originDisk($latest)
                || ($recorded['model'] ?? null) !== ($slot['model'] ?? null)) {
                return true;
            }

            if ($slot['status'] !== 'valid' && ($composition['degraded_reviewed'] ?? null) !== true) {
                return true;
            }
        }

        return count($seenSlots) !== 4;
    }

    /** @param  array<string, mixed>  $evidence */
    public function inputIsCurrent(MediaProcessingLog $log, array $evidence): bool
    {
        try {
            $diskName = $evidence['artifact_disk'] ?? null;
            $path = $evidence['input_path'] ?? null;
            $hash = $evidence['input_hash'] ?? null;

            if (! is_string($diskName) || $diskName !== ServiceArtifactDisk::name()
                || ! is_string($path) || ! is_string($hash)) {
                return false;
            }

            $input = Storage::disk($diskName)->get($path);

            return is_string($input) && hash('sha256', $input) === $hash
                && $this->snapshotMatchesCurrent($log, json_decode($input, true));
        } catch (Throwable) {
            return false;
        }
    }

    private function snapshotMatchesCurrent(MediaProcessingLog $log, mixed $snapshot): bool
    {
        if (! is_array($snapshot) || ! is_array($snapshot['source'] ?? null)) {
            return false;
        }

        $source = $snapshot['source'];

        if (($source['church_service_id'] ?? null) !== $log->church_service_id
            || ($source['transcript_path'] ?? null) !== $log->serviceTranscriptPath()
            || ($source['audio_timeline_path'] ?? null) !== $log->audio_timeline_path
            || ($source['rms_log_path'] ?? null) !== $log->rms_log_path) {
            return false;
        }

        if (($snapshot['validation_context']['recording_omits_songs'] ?? null)
            !== ValidationContext::recordingOmitsSongs($log->processing_metadata)
            || ! $this->oosItemsMatch($snapshot['oos_items'] ?? null, $this->currentOosItems($log))) {
            return false;
        }

        foreach ([
            'transcript_path' => 'transcript_hash',
            'audio_timeline_path' => 'audio_timeline_hash',
            'rms_log_path' => 'rms_log_hash',
        ] as $pathKey => $hashKey) {
            $path = $source[$pathKey] ?? null;

            if ($path === null && ($source[$hashKey] ?? null) === null) {
                continue;
            }

            if (! is_string($path) || ! is_string($source[$hashKey] ?? null)) {
                return false;
            }

            $current = Storage::disk(ServiceArtifactDisk::for($path))->get($path);

            if (! is_string($current) || hash('sha256', $current) !== $source[$hashKey]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Projection inserts detected items and renumbers canonical positions. The input stays
     * current when the source items have the same identities, content and relative order.
     *
     * @param  list<array{id: int, position: int, type: string, title: ?string, song_id: ?int}>  $current
     */
    private function oosItemsMatch(mixed $banked, array $current): bool
    {
        if (! is_array($banked) || ! array_is_list($banked) || count($banked) !== count($current)) {
            return false;
        }

        foreach ($banked as $index => $item) {
            if (! is_array($item) || ! is_int($item['position'] ?? null)) {
                return false;
            }

            $currentItem = $current[$index];
            unset($item['position'], $currentItem['position']);

            if ($item !== $currentItem) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{id: int, position: int, type: string, title: ?string, song_id: ?int}> */
    private function currentOosItems(MediaProcessingLog $log): array
    {
        if ($log->church_service_id === null) {
            return [];
        }

        return array_values($log->churchService()->first()?->items()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (ChurchServiceItem $item): bool => collect($item->provenanceSources())
                ->contains(fn (ChurchServiceItemSource $source): bool => ! $source->isDetected()))
            ->map(static fn (ChurchServiceItem $item): array => [
                'id' => (int) $item->id,
                'position' => (int) $item->position,
                'type' => $item->semanticSectionType()->value,
                'title' => $item->title,
                'song_id' => $item->song_id === null ? null : (int) $item->song_id,
            ])
            ->all() ?? []);
    }
}
