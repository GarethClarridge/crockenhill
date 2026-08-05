<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricProcessingResultImportPlan;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class HistoricMediaGraphPersister
{
    public function __construct(
        private readonly HistoricProcessingResultAssetTransfer $assets,
        private readonly HistoricProcessingResultInventory $inventory,
    ) {}

    /**
     * @return array{processing_log: MediaProcessingLog, created_assets: list<string>}
     */
    public function persist(HistoricProcessingResultImportPlan $plan): array
    {
        $graph = $plan->service['media_graph'];
        $processingId = $graph['processing_key'];
        $churchService = $this->resolveChurchService($plan, $graph);
        /**
         * The run must exist before any publication is written: sermons carry
         * `livestream_processing_id`, whose foreign key targets
         * `media_processing_logs.processing_id`. The run's own `sermon_id` back
         * reference can only be filled once the main publication exists, so it
         * is a second write rather than part of the insert.
         */
        $run = $this->createRun($graph, $churchService);
        $this->createSteps($processingId, $graph['steps'] ?? []);
        $segments = $this->createSegments($run, $graph['segments'] ?? []);
        $serviceItems = $this->resolveServiceItems($churchService, $graph['sections'] ?? []);
        $sections = $this->createSections($run, $graph['sections'] ?? [], $segments, $serviceItems);
        $this->linkServiceItems($processingId, $sections);
        $publications = $this->createPublications($graph['publications'] ?? [], $processingId, $run);
        $run->sermon_id = $publications['main']->id ?? null;
        $run->save();
        $this->createSongVideos($plan, $sections, $churchService);
        $destinations = $this->assetDestinations($plan, $run, $sections, $publications);
        $createdAssets = $this->assets->copyToDestinations($plan->assets, $destinations);

        try {
            $this->applyAllocatedPaths($plan, $run, $sections, $publications, $destinations);
        } catch (\Throwable $exception) {
            $this->assets->cleanup($createdAssets);

            throw $exception;
        }

        return ['processing_log' => $run->fresh() ?? $run, 'created_assets' => $createdAssets];
    }

    /**
     * Rebuild the live owner graph and verify every recorded canonical link and
     * production asset for an already-present import. Historic bundle metadata
     * is not sufficient evidence: the current rows and bytes are authoritative.
     */
    public function verifyExisting(
        HistoricProcessingResultImportPlan $plan,
        MediaProcessingLog $run,
    ): void {
        $run = $run->fresh() ?? $run;
        $inventory = $this->inventory->build($run);

        if (! hash_equals($plan->service['media_graph']['logical_hash'], $inventory['logical_hash'])) {
            throw new RuntimeException('Already-present historic media graph differs from the reviewed bundle.');
        }

        $sectionsByOrder = $run->serviceSections->keyBy('section_order');
        $sections = [];

        foreach ($inventory['sections'] as $payload) {
            $section = $sectionsByOrder->get($payload['section_order']);

            if (! $section instanceof ServiceSection) {
                throw new RuntimeException('Already-present historic media graph is missing a section.');
            }

            $sections[$payload['section_key']] = $section;
        }

        $publications = [];

        if ($run->sermon instanceof Sermon) {
            $publications['main'] = $run->sermon;
        }

        foreach ($inventory['publications'] as $payload) {
            $sectionKey = $payload['section_key'] ?? null;

            if (! is_string($sectionKey)) {
                continue;
            }

            $section = $sections[$sectionKey] ?? null;
            $publication = $section?->publishedSermon;

            if ($publication instanceof Sermon) {
                $publications[$sectionKey] = $publication;
            }
        }

        $destinations = $this->assetDestinations($plan, $run, $sections, $publications);
        $livePaths = $this->liveAssetPaths($plan, $run, $sections, $publications);

        foreach ($this->assets->expand($plan->assets) as $asset) {
            $role = $asset['role'];

            if (($livePaths[$role] ?? null) !== ($destinations[$role] ?? null)) {
                throw new RuntimeException("Already-present historic asset role {$role} has a different canonical link.");
            }
        }

        $this->assets->verifyDestinations($plan->assets, $destinations);
    }

    /**
     * The bundle's scripture_filters are deliberately not inserted. SermonObserver
     * owns that index and rebuilds it from `reference` on every save, so inserting
     * the bundle's rows either collides with the observer's on the unique key or is
     * silently replaced by them moments later. The contract classifies the field as
     * deterministically rebuilt for exactly this reason.
     *
     * @param  array<int, array<string, mixed>>  $publications
     * @return array<string, Sermon>
     */
    private function createPublications(
        array $publications,
        string $processingId,
        MediaProcessingLog $run,
    ): array {
        $created = [];

        foreach ($publications as $publication) {
            $key = $publication['section_key'] ?? 'main';
            /**
             * The inventory emits the run's own sermon and each section's published
             * sermon separately. When those are one production row both entries
             * carry the same slug, so the second is the first under another key
             * rather than a publication still to be created.
             */
            $reused = $this->publicationCreatedInThisAttempt($created, $publication['slug']);

            if ($reused instanceof Sermon) {
                $created[$key] = $reused;

                continue;
            }

            $preacher = $this->resolvePreacher($publication['preacher'] ?? null);
            $attributes = [
                'title' => $publication['title'],
                'date' => $publication['date'],
                'service' => $publication['service'],
                'content_type' => $publication['content_type'],
                'slug' => $publication['slug'],
                'filetype' => $publication['filetype'] ?? 'mp3',
                'reference' => $publication['reference'],
                'series' => $publication['series'] ?? null,
                'summary' => $publication['summary'] ?? null,
                'meta_description' => $publication['meta_description'] ?? null,
                'points' => $publication['points'] ?? null,
                'show_summary' => $publication['show_summary'] ?? false,
                'show_points' => $publication['show_points'] ?? false,
                'duration' => $publication['duration'] ?? null,
                'segment_start_time' => $publication['segment_start_time'] ?? null,
                'segment_end_time' => $publication['segment_end_time'] ?? null,
                'preacher_source' => $publication['preacher_source'] ?? null,
                'preacher_confidence' => $publication['preacher_confidence'] ?? null,
                'needs_preacher_review' => $publication['needs_preacher_review'] ?? false,
                'source_type' => $publication['source_type'] ?? null,
                'video_quality_status' => $publication['video_quality_status'] ?? null,
                'video_quality_reason' => $publication['video_quality_reason'] ?? null,
                'video_visibility_override' => $publication['video_visibility_override'] ?? null,
                'video_quality_assessed_at' => $publication['video_quality_assessed_at'] ?? null,
                'thumbnail_generated_at' => $publication['thumbnail_generated_at'] ?? null,
                'thumbnail_metadata' => $publication['thumbnail_metadata'] ?? null,
                'preacher' => $preacher?->name,
                'preacher_id' => $preacher?->id,
            ];
            $existing = $this->resolveExistingPublication($publication, $processingId, $run, $created);
            $sermon = $existing instanceof Sermon
                ? $this->convergePublication($existing, $attributes, $this->assertedFields($publication), $processingId)
                : Sermon::query()->create([...$attributes, 'livestream_processing_id' => $processingId]);
            $created[$key] = $sermon;
        }

        return $created;
    }

    /**
     * @param  array<string, Sermon>  $created
     */
    private function publicationCreatedInThisAttempt(array $created, string $slug): ?Sermon
    {
        foreach ($created as $sermon) {
            if ($sermon->slug === $slug) {
                return $sermon;
            }
        }

        return null;
    }

    /**
     * The fields the bundle actually asserts something about. A key the bundle
     * never carries is absent data, not a claim that the value is false or empty:
     * defaulting it and then comparing would block every import whose production
     * record has flags a human turned on.
     *
     * @param  array<string, mixed>  $publication
     * @return list<string>
     */
    private function assertedFields(array $publication): array
    {
        $asserted = array_keys($publication);

        /** `preacher_id` is derived from the payload's preacher block, not carried directly. */
        if (array_key_exists('preacher', $publication)) {
            $asserted[] = 'preacher_id';
        }

        return array_values(array_filter($asserted, 'is_string'));
    }

    /**
     * @param  array<string, mixed>  $publication
     * @param  array<string, Sermon>  $created
     */
    private function resolveExistingPublication(
        array $publication,
        string $processingId,
        MediaProcessingLog $run,
        array $created,
    ): ?Sermon {
        $candidateIds = Sermon::query()
            ->where('livestream_processing_id', $processingId)
            ->where('content_type', $publication['content_type'])
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $sourceHash = $run->file_hash;

        if (is_string($sourceHash) && $sourceHash !== '') {
            $candidateIds = [
                ...$candidateIds,
                ...MediaProcessingLog::query()
                    ->where('file_hash', $sourceHash)
                    ->where('id', '!=', $run->id)
                    ->whereNotNull('sermon_id')
                    ->whereHas('sermon', fn ($query) => $query->where('content_type', $publication['content_type']))
                    ->lockForUpdate()
                    ->pluck('sermon_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all(),
            ];
        }

        /**
         * A publication this attempt has already created carries the same
         * processing id and content type as the one being resolved, so it would
         * otherwise present itself as the strong identity for every later
         * publication of that type and collapse two rows into one.
         */
        $claimedIds = array_map(static fn (Sermon $sermon): int => $sermon->id, array_values($created));
        $candidateIds = array_values(array_diff(array_unique($candidateIds), $claimedIds));

        if (count($candidateIds) > 1) {
            throw new RuntimeException('Strong historic publication identities point to different sermons.');
        }

        $slugMatch = Sermon::query()->where('slug', $publication['slug'])->lockForUpdate()->first();

        if ($candidateIds === []) {
            if ($slugMatch instanceof Sermon) {
                throw new RuntimeException('Historic publication slug already belongs to a different sermon.');
            }

            return null;
        }

        $sermon = Sermon::query()->whereKey($candidateIds[0])->lockForUpdate()->firstOrFail();

        if ($slugMatch instanceof Sermon && $slugMatch->isNot($sermon)) {
            throw new RuntimeException('Historic publication slug points to a different sermon.');
        }

        if ($sermon->content_type->value !== $publication['content_type']) {
            throw new RuntimeException('Historic publication identity points to a different content type.');
        }

        return $sermon;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @param  list<string>  $asserted
     */
    private function convergePublication(
        Sermon $sermon,
        array $incoming,
        array $asserted,
        string $processingId,
    ): Sermon {
        $mergeableFields = [
            'title',
            'date',
            'service',
            'content_type',
            'slug',
            'filetype',
            'reference',
            'series',
            'summary',
            'meta_description',
            'points',
            'show_summary',
            'show_points',
            'duration',
            'segment_start_time',
            'segment_end_time',
            'preacher_source',
            'preacher_confidence',
            'needs_preacher_review',
            'preacher',
            'preacher_id',
            'source_type',
            'video_quality_status',
            'video_quality_reason',
            'video_visibility_override',
            'video_quality_assessed_at',
            'thumbnail_generated_at',
        ];
        $updates = [];

        foreach ($mergeableFields as $field) {
            if (! in_array($field, $asserted, true)) {
                continue;
            }

            $incomingValue = $incoming[$field] ?? null;
            $existingValue = $sermon->getAttribute($field);

            if ($incomingValue === null) {
                continue;
            }

            if ($existingValue === null) {
                $updates[$field] = $incomingValue;

                continue;
            }

            if ($this->comparableValue($existingValue, $field) !== $this->comparableValue($incomingValue, $field)) {
                throw new RuntimeException("Historic publication {$field} conflicts with the richer production record.");
            }
        }

        /**
         * `thumbnail_metadata` is structure, not a comparable value: the bundle's
         * copy carries staging paths that applyAllocatedPaths overwrites moments
         * later, so comparing it would conflict on every import. It is adopted
         * only when production has none — a live thumbnail set always wins, and
         * without this the candidate roles have no array to be remapped into.
         */
        $incomingThumbnails = $incoming['thumbnail_metadata'] ?? null;

        if (is_array($incomingThumbnails) && $sermon->thumbnail_metadata === null) {
            $updates['thumbnail_metadata'] = $incomingThumbnails;
        }

        if ($sermon->livestream_processing_id === null) {
            $updates['livestream_processing_id'] = $processingId;
        }

        if ($updates === []) {
            return $sermon;
        }

        $sermon->forceFill($updates)->save();

        return $sermon->fresh() ?? $sermon;
    }

    private function comparableValue(mixed $value, string $field): mixed
    {
        if ($field === 'date') {
            return $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d')
                : Carbon::parse((string) $value)->toDateString();
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            array_walk_recursive($value, function (mixed &$item): void {
                if ($item instanceof \BackedEnum) {
                    $item = $item->value;
                }
            });
            ksort($value);
        }

        return $value;
    }

    private function resolvePreacher(mixed $payload): ?Preacher
    {
        if (! is_array($payload)) {
            return null;
        }

        $slug = $payload['slug'] ?? null;
        $name = $payload['name'] ?? null;

        if (! is_string($slug) || ! is_string($name)) {
            throw new RuntimeException('Bundle preacher natural identity is incomplete.');
        }

        $preacher = Preacher::query()->where('slug', $slug)->lockForUpdate()->first();

        if ($preacher instanceof Preacher && $preacher->name !== $name) {
            throw new RuntimeException("Bundle preacher {$slug} conflicts with production.");
        }

        $preacher ??= Preacher::query()->create([
            'slug' => $slug,
            'name' => $name,
            'is_active' => true,
        ]);

        foreach ($payload['aliases'] ?? [] as $alias) {
            if (is_string($alias)) {
                $preacher->aliases()->firstOrCreate(['alias' => $alias]);
            }
        }

        return $preacher;
    }

    /** @param array<string, mixed> $graph */
    private function createRun(array $graph, ?ChurchService $churchService): MediaProcessingLog
    {
        $run = $graph['run'];

        return MediaProcessingLog::query()->create([
            'processing_id' => $graph['processing_key'],
            'processing_type' => $run['processing_type'],
            'status' => $run['status'],
            'current_step' => $run['current_step'],
            'original_filename' => $run['original_filename'],
            'file_hash' => $run['file_hash'],
            'file_size' => $run['file_size'],
            'duration' => $run['duration'],
            'extracted_date' => $run['extracted_date'],
            'extracted_service' => $run['extracted_service'],
            'sermon_start_time' => $run['sermon_start_time'],
            'sermon_end_time' => $run['sermon_end_time'],
            'threshold_method' => $run['threshold_method'],
            'adaptive_threshold' => $run['adaptive_threshold'],
            'rms_stats' => $run['rms_stats'],
            'processing_metadata' => [
                ...$graph['metadata'],
                'historic_promotion' => [
                    'logical_hash' => $graph['logical_hash'],
                ],
            ],
            'church_service_id' => $churchService?->id,
            'started_at' => $run['started_at'],
            'completed_at' => $run['completed_at'],
            'is_degraded_completion' => $run['is_degraded_completion'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $segmentPayloads
     * @return array<string, LivestreamSegment>
     */
    private function createSegments(MediaProcessingLog $run, array $segmentPayloads): array
    {
        $segments = [];

        foreach ($segmentPayloads as $payload) {
            $segment = $run->segments()->create(collect($payload)->except('segment_key')->all());
            $segments[$payload['segment_key']] = $segment;
        }

        return $segments;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sectionPayloads
     * @param  array<string, LivestreamSegment>  $segments
     * @param  array<string, ChurchServiceItem>  $serviceItems
     * @return array<string, ServiceSection>
     */
    private function createSections(
        MediaProcessingLog $run,
        array $sectionPayloads,
        array $segments,
        array $serviceItems,
    ): array {
        $sections = [];

        foreach ($sectionPayloads as $payload) {
            $segmentIds = [];

            foreach ($payload['source_segment_keys'] as $key) {
                if (! is_string($key) || ! isset($segments[$key])) {
                    throw new RuntimeException('Bundle section references an unknown segment key.');
                }

                $segmentIds[] = $segments[$key]->id;
            }
            $section = $run->serviceSections()->create([
                'section_type' => $payload['section_type'],
                'section_order' => $payload['section_order'],
                'title' => $payload['title'],
                'summary' => $payload['summary'],
                'start_time' => $payload['start_time'],
                'end_time' => $payload['end_time'],
                'duration' => $payload['duration'],
                'confidence' => $payload['confidence'],
                'status' => $payload['status'],
                'needs_manual_review' => $payload['needs_manual_review'],
                'source_segment_ids' => $segmentIds,
                'metadata' => $payload['metadata'] ?? null,
                'church_service_item_id' => $this->serviceItemId($payload['service_item_identity'] ?? null, $serviceItems),
                'matched_item_id' => $this->serviceItemId($payload['matched_item_identity'] ?? null, $serviceItems),
                'expected_item_id' => $this->serviceItemId($payload['expected_item_identity'] ?? null, $serviceItems),
                'song_match_type' => $payload['song_match_type'],
                /**
                 * Sections are born unpublished. `service_sections_publication_media_check`
                 * refuses an approved or published row whose extraction media and
                 * timestamp are absent, and those only exist once the assets have been
                 * copied. finaliseSections() applies the bundle's real publication state
                 * afterwards.
                 */
                'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
                'published_sermon_id' => null,
                'published_at' => null,
                'extracted_at' => null,
                'unpublished_expires_at' => null,
            ]);
            $sections[$payload['section_key']] = $section;
        }

        return $sections;
    }

    /**
     * A canonical item already bound to a different run is production data, not a
     * free slot: taking it would orphan the section that run extracted for it, and
     * would do so silently. Fail closed so the difference reaches preflight.
     *
     * @param  array<string, ServiceSection>  $sections
     */
    private function linkServiceItems(string $processingId, array $sections): void
    {
        foreach ($sections as $section) {
            $item = $section->church_service_item_id === null
                ? null
                : ChurchServiceItem::query()->find($section->church_service_item_id);

            if (! $item instanceof ChurchServiceItem) {
                continue;
            }

            $boundProcessingId = $item->livestream_processing_id;

            if (is_string($boundProcessingId) && $boundProcessingId !== $processingId) {
                throw new RuntimeException(
                    "Church service item {$item->canonical_identity} is already linked to processing run {$boundProcessingId}."
                );
            }

            $item->forceFill([
                'livestream_processing_id' => $processingId,
                'livestream_service_section_id' => $section->id,
            ])->save();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $sectionPayloads
     * @return array<string, ChurchServiceItem>
     */
    private function resolveServiceItems(?ChurchService $churchService, array $sectionPayloads): array
    {
        $identities = [];

        foreach ($sectionPayloads as $payload) {
            foreach (['service_item_identity', 'matched_item_identity', 'expected_item_identity'] as $key) {
                $identity = $payload[$key] ?? null;

                if (is_string($identity) && $identity !== '') {
                    $identities[$identity] = true;
                }
            }
        }

        if ($identities === []) {
            return [];
        }

        if (! $churchService instanceof ChurchService) {
            throw new RuntimeException('Historic media graph contains service item identities without a church service.');
        }

        $items = ChurchServiceItem::query()
            ->where('church_service_id', $churchService->id)
            ->whereIn('canonical_identity', array_keys($identities))
            ->lockForUpdate()
            ->get()
            ->groupBy('canonical_identity');
        $resolved = [];

        foreach (array_keys($identities) as $identity) {
            $matches = $items->get($identity, collect());

            if ($matches->count() !== 1) {
                throw new RuntimeException("Historic media graph cannot resolve church service item identity {$identity}.");
            }

            $resolved[$identity] = $matches->firstOrFail();
        }

        return $resolved;
    }

    /** @param  array<string, ChurchServiceItem>  $serviceItems */
    private function serviceItemId(?string $identity, array $serviceItems): ?int
    {
        if ($identity === null) {
            return null;
        }

        $item = $serviceItems[$identity] ?? null;

        if (! $item instanceof ChurchServiceItem) {
            throw new RuntimeException("Historic media graph cannot resolve church service item identity {$identity}.");
        }

        return $item->id;
    }

    /** @param  array<string, mixed>  $graph */
    private function resolveChurchService(HistoricProcessingResultImportPlan $plan, array $graph): ?ChurchService
    {
        $run = $graph['run'] ?? [];
        $date = $plan->service['date'] ?? $run['extracted_date'] ?? null;
        $service = $plan->service['service'] ?? $run['extracted_service'] ?? null;
        $service = $service instanceof \BackedEnum ? $service->value : $service;

        if (! is_string($date) || ! is_string($service)) {
            return null;
        }

        $matches = ChurchService::query()
            ->whereDate('date', $date)
            ->where('service', $service)
            ->lockForUpdate()
            ->get();

        if ($matches->count() > 1) {
            throw new RuntimeException("Historic media graph has multiple church services for {$date}|{$service}.");
        }

        return $matches->first();
    }

    /** @param array<int, array<string, mixed>> $steps */
    private function createSteps(string $processingId, array $steps): void
    {
        foreach ($steps as $step) {
            SermonProcessingStep::query()->create(['processing_id' => $processingId, ...$step]);
        }
    }

    /** @param array<string, ServiceSection> $sections */
    private function createSongVideos(
        HistoricProcessingResultImportPlan $plan,
        array $sections,
        ?ChurchService $churchService,
    ): void {
        foreach ($plan->service['media_graph']['song_videos'] ?? [] as $payload) {
            $section = $sections[$payload['section_key']] ?? null;
            $song = Song::query()->where('canonical_key', $payload['song_canonical_key'])->first();

            if (! $section instanceof ServiceSection || ! $song instanceof Song) {
                throw new RuntimeException('Bundle song video cannot resolve its section and canonical song.');
            }

            $churchServiceIdentity = $payload['church_service_identity'] ?? null;

            if ($churchServiceIdentity !== null && (! $churchService instanceof ChurchService
                || $churchServiceIdentity !== $this->churchServiceIdentity($churchService))) {
                throw new RuntimeException('Bundle song video points to a different church service.');
            }

            $existing = SongVideo::query()
                ->where('service_section_id', $section->id)
                ->first();

            if (! $existing instanceof SongVideo && $churchService instanceof ChurchService) {
                $existing = $this->resolveExistingSongVideo($plan, $payload, $section, $song, $churchService);
            }

            if ($existing instanceof SongVideo) {
                if ($existing->song_id !== $song->id) {
                    throw new RuntimeException('Bundle song video conflicts with the existing section recording.');
                }

                $updates = [];

                if ($existing->service_section_id !== $section->id) {
                    $updates['service_section_id'] = $section->id;
                }

                if ($existing->church_service_id === null && $churchService instanceof ChurchService) {
                    $updates['church_service_id'] = $churchService->id;
                }

                if ($updates !== []) {
                    $existing->forceFill($updates)->save();
                }

                continue;
            }

            SongVideo::query()->create([
                'song_id' => $song->id,
                'service_section_id' => $section->id,
                'church_service_id' => $churchService?->id,
                'video_file_path' => $payload['video_file_path'],
                'duration' => $payload['duration'],
                'recorded_date' => $payload['recorded_date'],
                'is_featured' => $payload['is_featured'],
            ]);
        }
    }

    /**
     * Match an existing song occurrence by the canonical song and portable
     * section identity. The asset hash is part of the identity so a damaged or
     * different recording fails closed instead of creating a silent duplicate.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveExistingSongVideo(
        HistoricProcessingResultImportPlan $plan,
        array $payload,
        ServiceSection $section,
        Song $song,
        ChurchService $churchService,
    ): ?SongVideo {
        $candidates = SongVideo::query()
            ->whereNotNull('service_section_id')
            ->whereHas('serviceSection.processingLog', fn ($query) => $query->where('church_service_id', $churchService->id))
            ->with('serviceSection.churchServiceItem')
            ->lockForUpdate()
            ->get()
            ->filter(fn (SongVideo $video): bool => $this->samePortableSection(
                $video->serviceSection,
                $section,
                $payload['service_item_identity'] ?? null,
            ));

        if ($candidates->count() > 1) {
            throw new RuntimeException('Historic song video identity points to multiple production occurrences.');
        }

        $existing = $candidates->first();

        if (! $existing instanceof SongVideo) {
            return null;
        }

        if ($existing->song_id !== $song->id) {
            throw new RuntimeException('Historic song video identity points to a different canonical song.');
        }

        $asset = $this->assetForRole(
            $plan->assets,
            HistoricProcessingResultAssetRole::songVideo($payload['section_key']),
        );

        if ($asset !== null && ! $this->assets->destinationMatches($asset, $existing->video_file_path)) {
            throw new RuntimeException('Historic song video identity points to different asset content.');
        }

        return $existing;
    }

    private function samePortableSection(
        ?ServiceSection $existing,
        ServiceSection $incoming,
        mixed $serviceItemIdentity,
    ): bool {
        if (! $existing instanceof ServiceSection) {
            return false;
        }

        $existingItemIdentity = $existing->churchServiceItem?->canonical_identity;

        return $existing->section_order === $incoming->section_order
            && $existing->section_type === $incoming->section_type
            && $existing->title === $incoming->title
            && (float) $existing->start_time === (float) $incoming->start_time
            && (float) $existing->end_time === (float) $incoming->end_time
            && $existingItemIdentity === $serviceItemIdentity;
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     * @return array<string, mixed>|null
     */
    private function assetForRole(array $assets, string $role): ?array
    {
        foreach ($assets as $asset) {
            if (in_array($role, $this->assets->roles($asset), true)) {
                return $asset;
            }
        }

        return null;
    }

    private function churchServiceIdentity(ChurchService $churchService): string
    {
        return $churchService->date->toDateString().'|'.$churchService->service->value;
    }

    /**
     * @param  array<string, ServiceSection>  $sections
     * @param  array<string, Sermon>  $publications
     * @return array<string, string|null>
     */
    private function liveAssetPaths(
        HistoricProcessingResultImportPlan $plan,
        MediaProcessingLog $run,
        array $sections,
        array $publications,
    ): array {
        $paths = [];
        $metadata = $run->processing_metadata?->toArray() ?? [];
        $artifacts = is_array($metadata['service_artifacts'] ?? null)
            ? $metadata['service_artifacts']
            : [];

        foreach ($this->assets->expand($plan->assets) as $asset) {
            $role = $asset['role'];
            $path = null;

            if (str_starts_with($role, 'run_')) {
                $path = $run->getAttribute(Str::after($role, 'run_'));
            } elseif (str_starts_with($role, 'publication:')) {
                $publicationRole = HistoricProcessingResultAssetRole::parsePublication($role);

                if ($publicationRole === null) {
                    continue;
                }

                $publication = $this->publicationForRole($publicationRole['publication_key'], $publications);

                if ($publication instanceof Sermon) {
                    $path = $this->publicationAssetPath($publication, $publicationRole);
                }
            } elseif (str_starts_with($role, 'section:')) {
                $sectionRole = HistoricProcessingResultAssetRole::parseSection($role);

                if ($sectionRole === null) {
                    continue;
                }

                $section = $sections[$sectionRole['section_key']] ?? null;

                if ($section instanceof ServiceSection) {
                    $path = $section->getAttribute($sectionRole['field']);
                }
            } elseif (str_starts_with($role, 'song_video:')) {
                $songVideoRole = HistoricProcessingResultAssetRole::parseSongVideo($role);
                $section = $songVideoRole === null ? null : $sections[$songVideoRole['section_key']] ?? null;
                $path = $section?->id === null
                    ? null
                    : SongVideo::query()->where('service_section_id', $section->id)->value('video_file_path');
            } else {
                foreach ($artifacts as $artifact) {
                    if (is_array($artifact) && HistoricProcessingResultAssetRole::serviceArtifact($artifact) === $role) {
                        $path = $artifact['path'] ?? null;

                        break;
                    }
                }
            }

            $paths[$role] = is_string($path) ? $path : null;
        }

        return $paths;
    }

    /**
     * @param  array{type: string, field: string, candidate_index?: int, publication_key: string}  $publicationRole
     */
    private function publicationAssetPath(Sermon $publication, array $publicationRole): mixed
    {
        if ($publicationRole['type'] === 'field') {
            return $publication->getAttribute($publicationRole['field']);
        }

        $metadata = $publication->thumbnail_metadata?->toArray() ?? [];

        if ($publicationRole['type'] === 'thumbnail_metadata') {
            return $metadata[$publicationRole['field']] ?? null;
        }

        $candidateIndex = $publicationRole['candidate_index'] ?? null;
        $candidates = $metadata['thumbnail_candidates'] ?? null;

        if (! is_int($candidateIndex) || ! is_array($candidates)) {
            return null;
        }

        $candidate = $candidates[$candidateIndex] ?? null;

        return is_array($candidate) ? $candidate[$publicationRole['field']] ?? null : null;
    }

    /**
     * @param  array<string, ServiceSection>  $sections
     * @param  array<string, Sermon>  $publications
     * @return array<string, string>
     */
    private function assetDestinations(
        HistoricProcessingResultImportPlan $plan,
        MediaProcessingLog $run,
        array $sections,
        array $publications,
    ): array {
        $destinations = [];
        $allocatedContent = [];
        $stagedPaths = array_fill_keys(array_column($plan->assets, 'path'), true);

        foreach ($this->assets->expand($plan->assets) as $asset) {
            $role = $asset['role'];
            $extension = pathinfo($asset['path'], PATHINFO_EXTENSION) ?: 'bin';

            if (str_starts_with($role, 'publication:')) {
                $publicationRole = HistoricProcessingResultAssetRole::parsePublication($role);
                $publication = $publicationRole === null
                    ? null
                    : $this->publicationForRole($publicationRole['publication_key'], $publications);

                if (! $publication instanceof Sermon) {
                    throw new RuntimeException("Cannot allocate publication asset role {$role}.");
                }

                $variant = $publicationRole['type'] === 'field'
                    ? null
                    : HistoricProcessingResultAssetRole::thumbnailVariant($publicationRole['field']);
                $filename = match ($publicationRole['type']) {
                    'field' => $this->assetFilename($publicationRole['field'], $extension),
                    'thumbnail_metadata' => "thumbnail-{$variant}.{$extension}",
                    'thumbnail_candidate' => "thumbnail-candidate-{$publicationRole['candidate_index']}-{$variant}.{$extension}",
                };

                $destinations[$role] = $this->existingPublicationAssetPath(
                    $publication,
                    $publicationRole,
                    "sermons/{$publication->id}/{$filename}",
                    $stagedPaths,
                );
            } elseif (str_starts_with($role, 'section:')) {
                $sectionRole = HistoricProcessingResultAssetRole::parseSection($role);
                $section = $sectionRole === null ? null : $sections[$sectionRole['section_key']] ?? null;

                if (! $section instanceof ServiceSection) {
                    throw new RuntimeException("Cannot allocate section asset role {$role}.");
                }

                $field = $sectionRole['field'] === 'extracted_audio_path' ? 'audio' : 'video';
                $destinations[$role] = "sermons/sections/{$section->id}/{$field}.{$extension}";
            } elseif (str_starts_with($role, 'song_video:')) {
                $songVideoRole = HistoricProcessingResultAssetRole::parseSongVideo($role);
                $section = $songVideoRole === null
                    ? null
                    : $sections[$songVideoRole['section_key']] ?? null;
                $video = $section?->id !== null
                    ? SongVideo::query()->where('service_section_id', $section->id)->first()
                    : null;

                if (! $video instanceof SongVideo) {
                    throw new RuntimeException("Cannot allocate song video asset role {$role}.");
                }

                $canonicalDestination = "sermons/songs/{$video->song_id}/{$section->id}.{$extension}";
                $productionDisk = Storage::disk((string) config('media-processing.storage.sermon_disk'));
                $destinations[$role] = $video->video_file_path !== ''
                    && ! isset($stagedPaths[$video->video_file_path])
                    && $productionDisk->exists($video->video_file_path)
                    ? $video->video_file_path
                    : $canonicalDestination;
            } else {
                $destinations[$role] = "service-transcripts/{$run->processing_id}/".basename($asset['path']);
            }

            /**
             * Two roles may legitimately share a destination when they name the same
             * physical content. Two different content objects landing on one key would
             * silently overwrite each other, so refuse it where it happens rather than
             * letting the copy fail later as an unexplained manifest mismatch.
             */
            $destination = $destinations[$role];
            $existing = $allocatedContent[$destination] ?? null;

            if ($existing !== null && $existing !== $asset['sha256']) {
                throw new RuntimeException(
                    "Asset role {$role} allocates destination {$destination}, which is already allocated to different content."
                );
            }

            $allocatedContent[$destination] = $asset['sha256'];
        }

        return $destinations;
    }

    /**
     * Existing published paths are part of the public URL surface. Reuse one only
     * when the asset transfer can verify its content at that path; a missing path
     * falls back to the create-only destination for this import.
     *
     * @param  array{type: string, field: string, candidate_index?: int, publication_key: string}  $publicationRole
     * @param  array<string, bool>  $stagedPaths
     */
    private function existingPublicationAssetPath(
        Sermon $publication,
        array $publicationRole,
        string $fallback,
        array $stagedPaths,
    ): string {
        $path = null;

        if ($publicationRole['type'] === 'field') {
            $path = $publication->getAttribute($publicationRole['field']);
        } else {
            $metadata = $publication->thumbnail_metadata?->toArray() ?? [];

            if ($publicationRole['type'] === 'thumbnail_metadata') {
                $path = $metadata[$publicationRole['field']] ?? null;
            } else {
                $candidateIndex = $publicationRole['candidate_index'] ?? null;
                $candidates = $metadata['thumbnail_candidates'] ?? null;

                if (is_int($candidateIndex) && is_array($candidates)) {
                    $candidate = $candidates[$candidateIndex] ?? null;
                    $path = is_array($candidate) ? $candidate[$publicationRole['field']] ?? null : null;
                }
            }
        }

        $productionDisk = Storage::disk((string) config('media-processing.storage.sermon_disk'));

        return is_string($path)
            && $path !== ''
            && ! isset($stagedPaths[$path])
            && $productionDisk->exists($path)
            ? $path
            : $fallback;
    }

    /**
     * @param  array<string, ServiceSection>  $sections
     * @param  array<string, Sermon>  $publications
     * @param  array<string, string>  $destinations
     */
    private function applyAllocatedPaths(
        HistoricProcessingResultImportPlan $plan,
        MediaProcessingLog $run,
        array $sections,
        array $publications,
        array $destinations,
    ): void {
        /**
         * Accumulate every remap in memory and write each record once. A sermon can
         * own a dozen thumbnail roles, and saving per role turned one publication
         * into a dozen round trips inside the import transaction.
         */
        $touchedPublications = [];
        $thumbnailMetadata = [];
        $touchedSections = [];

        foreach ($this->assets->expand($plan->assets) as $asset) {
            $role = $asset['role'];
            $path = $destinations[$role];

            if (str_starts_with($role, 'run_')) {
                $run->setAttribute(Str::after($role, 'run_'), $path);
            }

            $publicationRole = HistoricProcessingResultAssetRole::parsePublication($role);

            if ($publicationRole !== null) {
                $publicationKey = $publicationRole['publication_key'];
                $publication = $this->publicationForRole($publicationKey, $publications);

                if (! $publication instanceof Sermon) {
                    throw new RuntimeException("Cannot apply publication asset role {$role}.");
                }

                $touchedPublications[$publicationKey] = $publication;

                if ($publicationRole['type'] === 'field') {
                    $publication->setAttribute($publicationRole['field'], $path);
                } else {
                    $metadata = $thumbnailMetadata[$publicationKey]
                        ?? $publication->thumbnail_metadata?->toArray()
                        ?? [];

                    if ($publicationRole['type'] === 'thumbnail_metadata') {
                        $metadata[$publicationRole['field']] = $path;
                    } else {
                        $candidates = $metadata['thumbnail_candidates'] ?? null;

                        if (! is_array($candidates)) {
                            throw new RuntimeException("Cannot apply thumbnail candidate asset role {$role}.");
                        }

                        $candidates = array_values($candidates);

                        if (! isset($candidates[$publicationRole['candidate_index']])) {
                            throw new RuntimeException("Cannot apply thumbnail candidate asset role {$role}.");
                        }

                        $candidates[$publicationRole['candidate_index']][$publicationRole['field']] = $path;
                        $metadata['thumbnail_candidates'] = $candidates;
                    }

                    $thumbnailMetadata[$publicationKey] = $metadata;
                }

                continue;
            }

            $sectionRole = HistoricProcessingResultAssetRole::parseSection($role);

            if ($sectionRole !== null && isset($sections[$sectionRole['section_key']])) {
                $section = $sections[$sectionRole['section_key']];
                $section->setAttribute($sectionRole['field'], $path);
                $touchedSections[$sectionRole['section_key']] = $section;

                continue;
            }

            $songVideoRole = HistoricProcessingResultAssetRole::parseSongVideo($role);

            if ($songVideoRole !== null && isset($sections[$songVideoRole['section_key']])) {
                SongVideo::query()
                    ->where('service_section_id', $sections[$songVideoRole['section_key']]->id)
                    ->update(['video_file_path' => $path]);
            }
        }

        foreach ($touchedPublications as $publicationKey => $publication) {
            if (isset($thumbnailMetadata[$publicationKey])) {
                $publication->setAttribute('thumbnail_metadata', $thumbnailMetadata[$publicationKey]);
            }

            $publication->save();
        }

        $this->finaliseSections($plan, $sections, $publications, $touchedSections);
        $this->applyServiceArtifactPaths($run, $destinations);
        $run->save();
    }

    /**
     * Commit each section's extraction media before naming its publication state.
     * MySQL's `service_sections_publication_media_check` refuses an approved or
     * published row without extraction media and a timestamp, so collapsing these
     * two passes into one save reintroduces B2.
     *
     * @param  array<string, ServiceSection>  $sections
     * @param  array<string, Sermon>  $publications
     * @param  array<string, ServiceSection>  $touchedSections
     */
    private function finaliseSections(
        HistoricProcessingResultImportPlan $plan,
        array $sections,
        array $publications,
        array $touchedSections,
    ): void {
        $payloads = [];

        foreach ($plan->service['media_graph']['sections'] ?? [] as $payload) {
            if (is_array($payload) && is_string($payload['section_key'] ?? null)) {
                $payloads[$payload['section_key']] = $payload;
            }
        }

        /** @var array<string, array<string, mixed>> $finalisable */
        $finalisable = [];

        foreach (array_keys($sections) as $sectionKey) {
            $payload = $payloads[$sectionKey] ?? null;

            if (! is_array($payload)) {
                throw new RuntimeException("Cannot finalise unknown section {$sectionKey}.");
            }

            $finalisable[$sectionKey] = $payload;
        }

        foreach ($finalisable as $sectionKey => $payload) {
            $section = $sections[$sectionKey];
            $section->extracted_at = $payload['extracted_at'] ?? null;

            if (isset($touchedSections[$sectionKey]) || $section->extracted_at !== null) {
                $section->save();
            }
        }

        foreach ($finalisable as $sectionKey => $payload) {
            $section = $sections[$sectionKey];
            $this->assertPublicationStateIsSatisfiable($section, $payload, $sectionKey);

            $section->forceFill([
                'publication_status' => $payload['publication_status'],
                'published_sermon_id' => $publications[$sectionKey]->id ?? null,
                'published_at' => $payload['published_at'] ?? null,
                'unpublished_expires_at' => $payload['unpublished_expires_at'] ?? null,
            ])->save();
        }
    }

    /**
     * Restate `service_sections_publication_media_check` and
     * `service_sections_publication_link_check` in application terms before the
     * write. A bundle that promises a published section without its extraction
     * media is a bundle defect, and it should name the section rather than
     * surface as an anonymous MySQL constraint number.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertPublicationStateIsSatisfiable(
        ServiceSection $section,
        array $payload,
        string $sectionKey,
    ): void {
        $status = $payload['publication_status'];
        $status = $status instanceof ServiceSectionPublicationStatus
            ? $status
            : ServiceSectionPublicationStatus::from($status);

        if (! in_array($status, [
            ServiceSectionPublicationStatus::Approved,
            ServiceSectionPublicationStatus::Published,
        ], true)) {
            return;
        }

        $missing = [];

        if ($section->extracted_video_path === null) {
            $missing[] = 'extracted_video_path';
        }

        if ($section->extracted_at === null) {
            $missing[] = 'extracted_at';
        }

        if ($section->section_type !== ServiceSectionType::Song && $section->extracted_audio_path === null) {
            $missing[] = 'extracted_audio_path';
        }

        if ($status === ServiceSectionPublicationStatus::Published && ($payload['published_at'] ?? null) === null) {
            $missing[] = 'published_at';
        }

        if ($missing !== []) {
            throw new RuntimeException(
                "Section {$sectionKey} cannot become {$status->value} without ".implode(', ', $missing).'.'
            );
        }
    }

    /**
     * Rewrite recorded service-artifact paths to the destinations allocated for
     * their roles. A missing destination means the role this run derives and the
     * role the exporter emitted have diverged, which would otherwise leave a
     * staging path recorded against production media.
     *
     * @param  array<string, string>  $destinations
     */
    private function applyServiceArtifactPaths(MediaProcessingLog $run, array $destinations): void
    {
        $metadata = $run->processing_metadata?->toArray() ?? [];
        $serviceArtifacts = $metadata['service_artifacts'] ?? [];

        if (! is_array($serviceArtifacts)) {
            return;
        }

        foreach ($serviceArtifacts as $index => $artifact) {
            if (! is_array($artifact) || ! is_string($artifact['path'] ?? null)) {
                continue;
            }

            $role = HistoricProcessingResultAssetRole::serviceArtifact($artifact);

            if (! isset($destinations[$role])) {
                throw new RuntimeException("No production path was allocated for asset role {$role}.");
            }

            $serviceArtifacts[$index]['path'] = $destinations[$role];
        }

        $metadata['service_artifacts'] = $serviceArtifacts;
        $run->setAttribute('processing_metadata', $metadata);
    }

    /** @param array<string, Sermon> $publications */
    private function publicationForRole(string $publicationKey, array $publications): ?Sermon
    {
        $key = str_contains($publicationKey, ':main:') ? 'main' : Str::beforeLast($publicationKey, ':');

        return $publications[$key] ?? null;
    }

    private function assetFilename(string $field, string $extension): string
    {
        return match ($field) {
            'audio_file_path' => "audio.{$extension}",
            'video_file_path' => "video.{$extension}",
            'transcript_file_path' => "transcript.{$extension}",
            'thumbnail_file_path' => "thumbnail.{$extension}",
            default => throw new RuntimeException("Unknown publication asset field {$field}."),
        };
    }
}
