<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricProcessingResultAssetTransfer;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricProcessingResultBundleImporter;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use App\Support\CanonicalJson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ConvergeHistoricChurchService
{
    public function __construct(
        private readonly HistoricProcessingResultBundle $mediaBundles,
        private readonly ChurchServiceConvergenceBundle $convergenceBundles,
        private readonly HistoricProcessingResultBundleImporter $mediaImporter,
        private readonly ChurchServiceConvergenceBundleImporter $convergenceImporter,
        private readonly HistoricProcessingResultAssetTransfer $assets,
        private readonly HistoricProcessingResultInventory $inventory,
        private readonly IngestChurchServiceSourceRevision $ingestSourceRevision,
    ) {}

    /**
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     * @return array{church_service: ChurchService, processing_log: MediaProcessingLog, created_assets: list<string>}
     */
    public function execute(
        array $mediaBundle,
        array $convergenceBundle,
        int $mediaServiceIndex = 0,
        int $convergenceServiceIndex = 0,
    ): array {
        $mediaBundle = $this->mediaBundles->validate($mediaBundle);
        $convergenceBundle = $this->convergenceBundles->validate($convergenceBundle);
        $mediaPayload = $this->servicePayload($mediaBundle, $mediaServiceIndex, 'media');
        $convergencePayload = $this->servicePayload($convergenceBundle, $convergenceServiceIndex, 'convergence');
        $this->assertBundlesAgree($mediaBundle, $convergenceBundle, $mediaPayload, $convergencePayload);
        $mediaPlan = $this->mediaImporter->prepareService($mediaBundle, $mediaServiceIndex);
        $createdAssets = [];

        try {
            return DB::transaction(function () use (
                $mediaPlan,
                $mediaPayload,
                $convergenceBundle,
                $convergenceServiceIndex,
                &$createdAssets,
            ): array {
                $service = $this->lockService($mediaPayload);
                $mediaResult = $this->mediaImporter->persistPreparedService($mediaPlan, $mediaPlan->planHash);
                $createdAssets = $mediaResult['created_assets'];
                $run = $mediaResult['processing_log'];
                $this->linkRun($run, $service);
                $sections = $this->sectionsByPortableKey($run, $mediaPayload['media_graph']['sections']);
                $this->ingestLivestreamRevision($service, $run, $sections, $mediaPayload['livestream_source_revision']);

                $convergencePlan = $this->convergenceImporter->prepareService(
                    $convergenceBundle,
                    $convergenceServiceIndex,
                );

                if ($convergencePlan->churchService->isNot($service)) {
                    throw new RuntimeException('Convergence plan resolved a different church service.');
                }

                $service = $this->convergenceImporter->persistPreparedService(
                    $convergencePlan,
                    $convergencePlan->planHash,
                );
                $this->linkSectionsAndSongVideos($service, $sections);
                $this->verifyMediaGraph($run, $mediaPayload['media_graph']);

                return [
                    'church_service' => $service->fresh(['items.song']) ?? $service,
                    'processing_log' => $run->fresh() ?? $run,
                    'created_assets' => $createdAssets,
                ];
            });
        } catch (Throwable $exception) {
            $this->assets->cleanup($createdAssets);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function servicePayload(array $bundle, int $index, string $label): array
    {
        $payload = $bundle['services'][$index] ?? null;

        if (! is_array($payload)) {
            throw new RuntimeException("The {$label} service index {$index} does not exist.");
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     * @param  array<string, mixed>  $media
     * @param  array<string, mixed>  $convergence
     */
    private function assertBundlesAgree(
        array $mediaBundle,
        array $convergenceBundle,
        array $media,
        array $convergence,
    ): void {
        $checks = [
            $convergenceBundle['media_bundle_hash'] === $mediaBundle['bundle_hash'],
            $convergenceBundle['batch_hash'] === $mediaBundle['batch_hash'],
            CanonicalJson::hash($convergenceBundle['processing_fingerprint'])
                === CanonicalJson::hash($mediaBundle['processing_fingerprint']),
            $media['date'] === $convergence['date'],
            $media['service'] === $convergence['service'],
            $media['evidence_set_hash'] === $convergence['evidence_set_hash'],
            $media['pre_review_hash'] === $convergence['pre_review_hash'],
        ];

        if (in_array(false, $checks, true)) {
            throw new RuntimeException('Historic media and reviewed convergence bundles do not describe the same service result.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function lockService(array $payload): ChurchService
    {
        $services = ChurchService::query()
            ->whereDate('date', $payload['date'])
            ->where('service', $payload['service'])
            ->lockForUpdate()
            ->get();

        if ($services->count() !== 1) {
            throw new RuntimeException('Convergence requires exactly one production service for the natural identity.');
        }

        return $services->firstOrFail();
    }

    private function linkRun(MediaProcessingLog $run, ChurchService $service): void
    {
        if ($run->church_service_id !== null && $run->church_service_id !== $service->id) {
            throw new RuntimeException('Historic processing run is linked to a different church service.');
        }

        if ($run->church_service_id === null) {
            $run->forceFill(['church_service_id' => $service->id])->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return array<string, ServiceSection>
     */
    private function sectionsByPortableKey(MediaProcessingLog $run, array $payloads): array
    {
        $sectionsByOrder = $run->serviceSections()->get()->keyBy('section_order');
        $sections = [];

        foreach ($payloads as $payload) {
            $key = $payload['section_key'] ?? null;
            $order = $payload['section_order'] ?? null;
            $section = is_int($order) ? $sectionsByOrder->get($order) : null;

            if (! is_string($key) || ! $section instanceof ServiceSection) {
                throw new RuntimeException('Imported media graph does not reproduce its portable section identities.');
            }

            $sections[$key] = $section;
        }

        if (count($sections) !== $sectionsByOrder->count()) {
            throw new RuntimeException('Imported media graph contains unexpected service sections.');
        }

        return $sections;
    }

    /**
     * @param  array<string, ServiceSection>  $sections
     * @param  array<string, mixed>  $payload
     */
    private function ingestLivestreamRevision(
        ChurchService $service,
        MediaProcessingLog $run,
        array $sections,
        array $payload,
    ): void {
        $assertions = array_values(array_map(function (array $assertion) use ($run, $sections): array {
            $canonicalKey = $assertion['song_canonical_key'] ?? null;
            $assertion['song_id'] = is_string($canonicalKey)
                ? Song::query()->where('canonical_key', $canonicalKey)->value('id')
                : null;

            if (is_string($canonicalKey) && $assertion['song_id'] === null) {
                throw new RuntimeException("Livestream assertion song does not exist: {$canonicalKey}.");
            }

            $metadata = is_array($assertion['metadata'] ?? null) ? $assertion['metadata'] : [];
            $sectionKey = $metadata['livestream_service_section_key'] ?? null;
            unset($metadata['livestream_service_section_key']);

            if (is_string($sectionKey)) {
                $section = $sections[$sectionKey] ?? null;

                if (! $section instanceof ServiceSection) {
                    throw new RuntimeException("Livestream assertion references unknown section {$sectionKey}.");
                }

                $metadata['livestream_processing_id'] = $run->processing_id;
                $metadata['livestream_service_section_id'] = $section->id;
            }

            $assertion['metadata'] = $metadata === [] ? null : $metadata;

            return $assertion;
        }, $payload['assertions']));

        $result = $this->ingestSourceRevision->execute(
            $service,
            new ChurchServiceSourceRevision(
                source: ChurchServiceSource::Livestream,
                sourceKey: $payload['source_key'],
                inputHash: $payload['input_hash'],
                assertions: $assertions,
                processingFingerprint: $payload['processing_fingerprint'],
                serviceContent: $payload['service_content'],
                batchHash: $payload['batch_hash'],
                payloadComplete: $payload['payload_complete'],
                capturedAt: Carbon::parse($payload['captured_at']),
            ),
        );

        if (! hash_equals($payload['revision_hash'], $result->sourceRecord->revision_hash)) {
            throw new RuntimeException('Remapped Livestream revision differs from the reviewed source revision.');
        }
    }

    /** @param array<string, ServiceSection> $sections */
    private function linkSectionsAndSongVideos(ChurchService $service, array $sections): void
    {
        $items = $service->items()->get()->keyBy('livestream_service_section_id');

        foreach ($sections as $section) {
            $item = $items->get($section->id);

            if ($item instanceof ChurchServiceItem) {
                $section->forceFill(['church_service_item_id' => $item->id])->save();
            }
        }

        SongVideo::query()
            ->whereIn('service_section_id', collect($sections)->pluck('id'))
            ->update(['church_service_id' => $service->id]);
    }

    /** @param array<string, mixed> $graph */
    private function verifyMediaGraph(MediaProcessingLog $run, array $graph): void
    {
        $actual = $this->inventory->build($run->fresh() ?? $run);

        if (! hash_equals($graph['logical_hash'], $actual['logical_hash'])) {
            throw new RuntimeException('Linked historic media graph differs from the imported portable graph.');
        }

        $unlinkedSongVideos = SongVideo::query()
            ->whereHas('serviceSection', fn ($query) => $query->where('media_processing_log_id', $run->id))
            ->whereNull('church_service_id')
            ->exists();

        if ($unlinkedSongVideos) {
            throw new RuntimeException('Imported song videos were not linked to the church service.');
        }
    }
}
