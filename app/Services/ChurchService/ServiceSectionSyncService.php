<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ServiceSectionPublicationStatus;
use App\Exceptions\UnplacedContentHoldException;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use App\Support\ServiceSectionConfidence;
use App\Traits\SanitizesLogData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Synchronizes classified section data with the persistent database state.
 *
 * This service handles the idempotent upsert of service sections for a processing log,
 * ensuring that material changes to a section's "signature" (type, timing, item link)
 * trigger appropriate asset cleanup and publication notifications while preserving
 * existing manual metadata edits where possible.
 *
 * Owns the canonical ClassifiedSection payload shape: every producer
 * The LLM structure mapper must emit this exact array.
 *
 * @phpstan-type ClassifiedSection array{
 *     church_service_item_id: int|null,
 *     section_type: string,
 *     section_order: int,
 *     title: ?string,
 *     summary?: ?string,
 *     start_time: float,
 *     end_time: float,
 *     duration: float,
 *     confidence: float,
 *     status: string,
 *     needs_manual_review: bool,
 *     source_segment_ids: array<int, int>,
 *     metadata: array<string, mixed>
 * }
 * @phpstan-type HeldContent array{
 *     id: int,
 *     section_type: string,
 *     start_time: float,
 *     end_time: float,
 *     held: bool,
 *     holds: list<array<string, mixed>>
 * }
 */
class ServiceSectionSyncService
{
    use SanitizesLogData;

    /** Added to a row's id to park it clear of every real position while positions are rewritten. */
    private const PARKED_ORDER_OFFSET = 1_000_000_000;

    public function __construct(
        private readonly SectionPublicationHandlerFactory $handlerFactory,
    ) {}

    /**
     * Clean up extracted assets and notify the publication handler before deleting a section.
     * Always use this instead of calling $section->delete() directly.
     */
    public function removeSection(ServiceSection $section): void
    {
        $this->cleanupExtractedAssets($section);
        $this->notifyHandlerOfRemoval($section);
        $section->delete();
    }

    /**
     * Perform an idempotent sync of all classified sections for a processing log.
     *
     * @param  array<int, ClassifiedSection>  $classifiedSections
     */
    public function sync(MediaProcessingLog $processingLog, array $classifiedSections): void
    {
        $defaultPublicationStatus = ServiceSectionPublicationStatus::NotApplicable->value;

        DB::transaction(function () use ($processingLog, $classifiedSections, $defaultPublicationStatus): void {
            $existingByOrder = ServiceSection::query()
                ->where('media_processing_log_id', $processingLog->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('section_order');

            /*
             * Read before any row is rewritten: a held row can be refilled with other
             * content earlier in this loop than the section its hold belongs on.
             * Released records are read too, so their history moves with the content.
             */
            $heldContent = array_values($existingByOrder
                ->filter(fn (ServiceSection $section): bool => $this->contentHoldsOf($section) !== [])
                ->map(fn (ServiceSection $section): array => [
                    'id' => $section->id,
                    'section_type' => $section->section_type->value,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'held' => HoldSectionForContentReview::isHeld($this->reviewFlagsOf($section->metadata?->toArray() ?? [])),
                    'holds' => $this->contentHoldsOf($section),
                ])
                ->all());

            $this->refuseUnplacedContentHolds(
                array_values(array_filter($heldContent, static fn (array $held): bool => $held['held'])),
                $classifiedSections,
            );
            $this->keepUnplacedHoldHistory($processingLog, $heldContent, $classifiedSections);

            $pairs = $this->pairWithExisting($existingByOrder->values()->all(), $classifiedSections);
            $claimedIds = array_map(static fn (ServiceSection $section): int => $section->id, $pairs);
            $this->parkMovingRows($existingByOrder->all(), $pairs, $classifiedSections);

            foreach ($classifiedSections as $index => $sectionData) {
                $payload = [
                    'media_processing_log_id' => $processingLog->id,
                    'church_service_item_id' => $sectionData['church_service_item_id'],
                    'section_type' => $sectionData['section_type'],
                    'section_order' => $sectionData['section_order'],
                    'title' => $sectionData['title'],
                    'summary' => $sectionData['summary'] ?? null,
                    'start_time' => $sectionData['start_time'],
                    'end_time' => $sectionData['end_time'],
                    'duration' => $sectionData['duration'],
                    'confidence' => $this->resolveConfidence($sectionData),
                    'status' => $sectionData['status'],
                    'needs_manual_review' => $sectionData['needs_manual_review'],
                    'source_segment_ids' => $sectionData['source_segment_ids'],
                    'metadata' => $sectionData['metadata'],
                ];

                $existing = $pairs[$index] ?? null;

                $validationPayload = array_merge($payload, [
                    'publication_status' => $existing instanceof ServiceSection
                        ? $existing->publication_status->value
                        : $defaultPublicationStatus,
                ]);

                Validator::make($validationPayload, ServiceSection::validationRules())->validate();

                if ($existing instanceof ServiceSection) {
                    $signatureChanged = $this->hasMaterialSignatureChange($existing, $payload);

                    if ($signatureChanged) {
                        $this->cleanupExtractedAssets($existing);
                        $this->notifyHandlerOfRemoval($existing);

                        $payload = array_merge(
                            $payload,
                            $this->supersededReplacementPayload($existing, $payload)
                        );
                    } else {
                        $payload['metadata'] = $this->mergeExistingMetadata($existing, $payload['metadata']);
                    }

                    if ($signatureChanged || ! $this->songMatchWasReviewed($existing)) {
                        $payload = $this->withoutSongBinding($payload);
                    }

                    $existing->fill($this->withContentHolds($payload, $heldContent));
                    $existing->save();

                    continue;
                }

                ServiceSection::query()->create(array_merge(
                    $this->withContentHolds($payload, $heldContent),
                    [
                        'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
                        'song_match_type' => null,
                        'published_sermon_id' => null,
                        'published_at' => null,
                        'extracted_video_path' => null,
                        'extracted_audio_path' => null,
                        'extracted_at' => null,
                        'unpublished_expires_at' => null,
                    ]
                ));
            }

            $staleSections = $existingByOrder
                ->reject(static fn (ServiceSection $section): bool => in_array($section->id, $claimedIds, true));

            foreach ($staleSections as $staleSection) {
                $this->cleanupExtractedAssets($staleSection);
                $this->notifyHandlerOfRemoval($staleSection);
                $staleSection->delete();
            }
        });
    }

    /**
     * Which stored row each incoming section updates.
     *
     * A stored row the incoming section is materially identical to is claimed first, wherever
     * either sits in the order; only then does an unclaimed row at the same position take a
     * section. Pairing by position alone compared every section after an inserted or removed
     * one with its neighbour's row and deleted that row's extracted media.
     *
     * @param  array<int, ServiceSection>  $existing
     * @param  array<int, ClassifiedSection>  $classifiedSections
     * @return array<int, ServiceSection>
     */
    private function pairWithExisting(array $existing, array $classifiedSections): array
    {
        $pairs = [];
        $claimed = [];

        foreach ($classifiedSections as $index => $sectionData) {
            $identity = $this->materialIdentity($this->buildSignatureFromPayload($sectionData));

            foreach ($existing as $candidate) {
                if (! isset($claimed[$candidate->id])
                    && $this->materialIdentity($this->buildSignatureFromExisting($candidate)) === $identity) {
                    $pairs[$index] = $candidate;
                    $claimed[$candidate->id] = true;

                    break;
                }
            }
        }

        foreach ($classifiedSections as $index => $sectionData) {
            if (isset($pairs[$index])) {
                continue;
            }

            foreach ($existing as $candidate) {
                if (! isset($claimed[$candidate->id]) && $candidate->section_order === $sectionData['section_order']) {
                    $pairs[$index] = $candidate;
                    $claimed[$candidate->id] = true;

                    break;
                }
            }
        }

        return $pairs;
    }

    /**
     * Moves every row that changes position, or is about to be removed, to a temporary position
     * past any real one, so no row is written onto a position another still holds (unique per
     * run, unsigned).
     *
     * @param  array<int, ServiceSection>  $existing
     * @param  array<int, ServiceSection>  $pairs
     * @param  array<int, ClassifiedSection>  $classifiedSections
     */
    private function parkMovingRows(array $existing, array $pairs, array $classifiedSections): void
    {
        $staying = [];

        foreach ($pairs as $index => $section) {
            if ($section->section_order === $classifiedSections[$index]['section_order']) {
                $staying[$section->id] = true;
            }
        }

        foreach ($existing as $section) {
            if (! isset($staying[$section->id])) {
                $parked = self::PARKED_ORDER_OFFSET + $section->id;
                ServiceSection::query()->whereKey($section->id)->toBase()->update(['section_order' => $parked]);
                $section->section_order = $parked;
                $section->syncOriginalAttribute('section_order');
            }
        }
    }

    /**
     * @param  array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     confidence: float|null,
     *     metadata: array<string, mixed>
     * }  $incomingPayload
     */
    private function hasMaterialSignatureChange(ServiceSection $existing, array $incomingPayload): bool
    {
        return $this->materialIdentity($this->buildSignatureFromExisting($existing))
            !== $this->materialIdentity($this->buildSignatureFromPayload($incomingPayload));
    }

    /**
     * The part of a signature that decides whether extracted media still belongs to the section.
     *
     * A section bound to an order-of-service item is identified by that item, so its title text
     * is not compared at all; an unbound title is compared without case or punctuation. Either
     * way a respelling ("Speak O Lord" → "Speak, O Lord") keeps the media, where comparing the
     * raw text once deleted a published clip and its song video (canary 9, run 1221). The new
     * title is still written.
     *
     * @param  array{church_service_item_id: int|null, section_type: string, title: ?string, start_time: float, end_time: float}  $signature
     * @return array{church_service_item_id: int|null, section_type: string, title: ?string, start_time: float, end_time: float}
     */
    private function materialIdentity(array $signature): array
    {
        $title = $signature['title'];

        $signature['title'] = match (true) {
            $signature['church_service_item_id'] !== null, $title === null => null,
            default => trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title))),
        };

        return $signature;
    }

    /**
     * @param  array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     confidence: float|null,
     *     metadata: array<string, mixed>
     * }  $incomingPayload
     * @return array<string, mixed>
     */
    private function supersededReplacementPayload(ServiceSection $existing, array $incomingPayload): array
    {
        $now = CarbonImmutable::now();
        $previousSignature = $this->buildSignatureFromExisting($existing);
        $nextSignature = $this->buildSignatureFromPayload($incomingPayload);
        $supersedeMetadata = [
            'at' => $now->toIso8601String(),
            'previous_signature' => $previousSignature,
            'next_signature' => $nextSignature,
            'previous_published_sermon_id' => $existing->published_sermon_id,
        ];

        $isPublishableType = $this->isPublishableType($incomingPayload['section_type']);

        if ($existing->published_sermon_id !== null) {
            Log::warning('Published service section superseded by classification refresh', $this->sanitizeArrayForLog([
                'service_section_id' => $existing->id,
                'processing_log_id' => $existing->media_processing_log_id,
                'published_sermon_id' => $existing->published_sermon_id,
            ]));
        }

        return [
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'published_sermon_id' => null,
            'published_at' => null,
            'extracted_video_path' => null,
            'extracted_audio_path' => null,
            'extracted_at' => null,
            'unpublished_expires_at' => null,
            'metadata' => array_merge(
                $incomingPayload['metadata'],
                [
                    'superseded' => $supersedeMetadata,
                    'publishable_type_after_supersede' => $isPublishableType,
                ]
            ),
        ];
    }

    private function notifyHandlerOfRemoval(ServiceSection $section): void
    {
        $handler = $this->handlerFactory->forSection($section);

        if ($handler !== null) {
            $handler->onSectionRemoved($section);

            return;
        }

        if ($section->published_sermon_id === null) {
            return;
        }

        Log::warning('Published service section removed but no handler registered', $this->sanitizeArrayForLog([
            'service_section_id' => $section->id,
            'processing_log_id' => $section->media_processing_log_id,
            'published_sermon_id' => $section->published_sermon_id,
            'signature' => $this->buildSignatureFromExisting($section),
        ]));
    }

    /**
     * @return array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float
     * }
     */
    private function buildSignatureFromExisting(ServiceSection $section): array
    {
        return [
            'church_service_item_id' => $section->church_service_item_id,
            'section_type' => $section->section_type->value,
            'title' => $section->title,
            'start_time' => (float) $section->start_time,
            'end_time' => (float) $section->end_time,
        ];
    }

    /**
     * @param  array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     confidence: float|null,
     *     metadata: array<string, mixed>
     * }  $payload
     * @return array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float
     * }
     */
    private function buildSignatureFromPayload(array $payload): array
    {
        return [
            'church_service_item_id' => $payload['church_service_item_id'],
            'section_type' => $payload['section_type'],
            'title' => $payload['title'],
            'start_time' => (float) $payload['start_time'],
            'end_time' => (float) $payload['end_time'],
        ];
    }

    /**
     * @param  array{
     *     confidence?: float|null,
     *     metadata: array<string, mixed>
     * }  $sectionData
     */
    private function resolveConfidence(array $sectionData): float
    {
        $confidence = $sectionData['confidence'] ?? null;

        return ServiceSectionConfidence::resolve(
            is_numeric($confidence) ? (float) $confidence : null,
            $sectionData['metadata']
        );
    }

    private function isPublishableType(string $sectionType): bool
    {
        /** @var array<string, class-string> $handlers */
        $handlers = config('media-processing.section_publishing.handlers', []);

        return isset($handlers[$sectionType]);
    }

    /**
     * @param  array<string, mixed>  $incomingMetadata
     * @return array<string, mixed>
     */
    private function mergeExistingMetadata(ServiceSection $existing, array $incomingMetadata): array
    {
        $existingMetadata = $existing->metadata?->toArray() ?? [];
        $merged = array_merge($existingMetadata, $incomingMetadata);

        // A re-detection refreshes the proposal but must not discard the type an
        // operator confirmed while the section is still a short talk.
        $reviewedType = $existingMetadata['talk_type']['reviewed'] ?? null;

        if (is_array($merged['talk_type'] ?? null) && is_array($reviewedType)) {
            $merged['talk_type']['reviewed'] = $reviewedType;
        }

        return $merged;
    }

    /**
     * Hand a re-detected section's song back to matching.
     *
     * A binding is a conclusion drawn by the matching code of its day, and matching
     * skips a section it finds confirmed. A re-detection used to keep the confirmed
     * type while a changed section lost the match record behind it, so 136 historic
     * songs stayed confirmed on no evidence at all, and no later identity rule (two
     * independent sources, the lyric identity check, catalogue title resolution) could
     * reach an unchanged one. A song whose binding a person reviewed keeps it while
     * its content is unchanged.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutSongBinding(array $payload): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = $payload['metadata'];
        unset($metadata['transcript_song_match'], $metadata['identity_sources'], $metadata['lyric_identity_check']);

        $payload['metadata'] = $metadata;
        $payload['song_match_type'] = null;

        return $payload;
    }

    private function songMatchWasReviewed(ServiceSection $section): bool
    {
        $reviewedAt = $section->metadata?->toArray()['manual_review']['song_match_reviewed_at'] ?? null;

        return is_string($reviewedAt) && $reviewedAt !== '';
    }

    /**
     * Refuse, before anything is written, a re-detection that leaves an operator's
     * content hold with no section to carry it.
     *
     * Rows are kept by order, and a hold used to be overwritten with the rest of the
     * detection's review state, so a re-run could release content an operator had
     * proven wrong. Whether the defect went with the content only an operator can say.
     *
     * @param  list<HeldContent>  $heldContent
     * @param  array<int, ClassifiedSection>  $classifiedSections
     *
     * @throws UnplacedContentHoldException
     */
    private function refuseUnplacedContentHolds(array $heldContent, array $classifiedSections): void
    {
        $unplaced = array_values(array_filter(
            $heldContent,
            fn (array $held): bool => ! collect($classifiedSections)
                ->contains(fn (array $sectionData): bool => $this->coversHeldContent($held, $sectionData)),
        ));

        if ($unplaced !== []) {
            throw UnplacedContentHoldException::forSections($unplaced);
        }
    }

    /**
     * Keep, on the run, the hold history of released content no incoming section covers.
     *
     * A released hold refuses nothing, so the re-detection may drop its content; the
     * record of what was found there and how it was settled is evidence all the same,
     * and would otherwise go with the deleted row.
     *
     * @param  list<HeldContent>  $heldContent
     * @param  array<int, ClassifiedSection>  $classifiedSections
     */
    private function keepUnplacedHoldHistory(MediaProcessingLog $processingLog, array $heldContent, array $classifiedSections): void
    {
        $unplaced = array_values(array_filter(
            $heldContent,
            fn (array $held): bool => ! $held['held'] && ! collect($classifiedSections)
                ->contains(fn (array $sectionData): bool => $this->coversHeldContent($held, $sectionData)),
        ));

        if ($unplaced === []) {
            return;
        }

        $recordedAt = CarbonImmutable::now()->toIso8601String();

        $processingLog->writeProcessingMetadata(static function (array $metadata) use ($unplaced, $recordedAt): array {
            $history = is_array($metadata['unplaced_content_hold_records'] ?? null) ? $metadata['unplaced_content_hold_records'] : [];

            foreach ($unplaced as $held) {
                $history[] = [
                    'section_id' => $held['id'],
                    'section_type' => $held['section_type'],
                    'start_time' => $held['start_time'],
                    'end_time' => $held['end_time'],
                    'holds' => $held['holds'],
                    'recorded_at' => $recordedAt,
                ];
            }

            $metadata['unplaced_content_hold_records'] = $history;

            return $metadata;
        });
    }

    /**
     * Carry each content hold record — live or released — to the incoming sections
     * of its type that overlap the span it was on.
     *
     * A hold follows its content, not its row: after a re-detection shifts the
     * orders, the held row can hold different content and the held content can
     * arrive at another order. So a row keeps no record for content it no longer
     * covers (run 1287's reading kept the song's "wrong song" record on 2026-09-23).
     * Records from a row an operator had released are stamped released, so they
     * cannot come back live beside a hold that is.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<HeldContent>  $heldContent
     * @return array<string, mixed>
     */
    private function withContentHolds(array $payload, array $heldContent): array
    {
        $covering = collect($heldContent)->filter(fn (array $held): bool => $this->coversHeldContent($held, $payload));

        /** @var array<string, mixed> $metadata */
        $metadata = $payload['metadata'];
        unset($metadata[HoldSectionForContentReview::METADATA_KEY]);

        $holds = HoldSectionForContentReview::mergeRecords([], array_values($covering
            ->flatMap(fn (array $held): array => $held['held'] ? $held['holds'] : array_map($this->asReleased(...), $held['holds']))
            ->all()));

        if ($holds !== []) {
            $metadata[HoldSectionForContentReview::METADATA_KEY] = $holds;
        }

        if ($covering->contains(static fn (array $held): bool => $held['held'])) {
            $flags = $this->reviewFlagsOf($metadata);
            $metadata['review_flags'] = HoldSectionForContentReview::isHeld($flags)
                ? $flags
                : [...$flags, HoldSectionForContentReview::FLAG];
            $payload['needs_manual_review'] = true;
        }

        $payload['metadata'] = $metadata;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function asReleased(array $record): array
    {
        return HoldSectionForContentReview::isLive($record)
            ? [...$record, 'released_at' => CarbonImmutable::now()->toIso8601String()]
            : $record;
    }

    /**
     * @param  HeldContent  $held
     * @param  array<string, mixed>  $sectionData
     */
    private function coversHeldContent(array $held, array $sectionData): bool
    {
        return $sectionData['section_type'] === $held['section_type']
            && (float) $sectionData['start_time'] < $held['end_time']
            && (float) $sectionData['end_time'] > $held['start_time'];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private function reviewFlagsOf(array $metadata): array
    {
        return array_values(array_filter(
            is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [],
            'is_string',
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentHoldsOf(ServiceSection $section): array
    {
        $holds = $section->metadata?->toArray()[HoldSectionForContentReview::METADATA_KEY] ?? [];

        return array_values(array_filter(is_array($holds) ? $holds : [], 'is_array'));
    }

    private function cleanupExtractedAssets(ServiceSection $section): void
    {
        $this->deleteResolvedPath($section, $section->extracted_video_path);
        $this->deleteResolvedPath($section, $section->extracted_audio_path);
    }

    private function deleteResolvedPath(ServiceSection $section, ?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        $disk = $section->extractedAssetDisk();

        try {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (\Throwable $throwable) {
            Log::warning('Failed to clean up extracted section asset on disk', $this->sanitizeArrayForLog([
                'disk' => $disk,
                'path' => $path,
                'error' => $throwable->getMessage(),
            ]));
        }
    }
}
