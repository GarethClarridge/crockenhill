<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\Scripture\ScriptureReferenceResolver;
use Illuminate\Database\Eloquent\Collection;

class SermonPageContextService
{
    public function __construct(
        private readonly ScriptureReferenceResolver $scriptureReferences,
    ) {}

    /**
     * @return array{reading_reference:?string, reading_url:?string}
     */
    public function build(Sermon $sermon): array
    {
        $readingReference = $this->resolveReadingReference($sermon);

        return [
            'reading_reference' => $readingReference,
            'reading_url' => $readingReference === null ? null : $this->bibleGatewayUrl($readingReference),
        ];
    }

    /**
     * The reading the page names beside the sermon.
     *
     * First the reading the sermon media was cut with, found inside the run's
     * recorded extraction spans; then the reading whose reference overlaps the
     * sermon's own; otherwise none. The service's first reading by order is
     * not evidence of either, and naming it was wrong on 157 of 438 sermons.
     */
    private function resolveReadingReference(Sermon $sermon): ?string
    {
        $processingLogId = $this->resolveProcessingLogId($sermon);

        if ($processingLogId === null) {
            return null;
        }

        $readings = $this->queryReadingSections($processingLogId)
            ->filter(fn (ServiceSection $section): bool => $this->readingReference($section) !== null);

        if ($readings->isEmpty()) {
            return null;
        }

        $spans = MediaProcessingLog::query()
            ->select(['id', 'processing_metadata'])
            ->find($processingLogId)
            ?->recordedSermonExtractionSpans() ?? [];

        $readingInMedia = $readings->first(fn (ServiceSection $section): bool => $this->liesWithinSpans($section, $spans));

        if ($readingInMedia instanceof ServiceSection) {
            return $this->readingReference($readingInMedia);
        }

        $sermonReference = $sermon->reference;

        if (! is_string($sermonReference) || trim($sermonReference) === '') {
            return null;
        }

        $matchingReading = $readings->first(fn (ServiceSection $section): bool => $this->scriptureReferences->referencesOverlap(
            (string) $this->readingReference($section),
            $sermonReference,
        ));

        return $matchingReading instanceof ServiceSection ? $this->readingReference($matchingReading) : null;
    }

    private function readingReference(ServiceSection $readingSection): ?string
    {
        $metadata = $readingSection->metadata?->toArray() ?? [];
        $metadataReference = $metadata['reading_reference'] ?? null;
        if (is_string($metadataReference) && trim($metadataReference) !== '') {
            return trim($metadataReference);
        }

        $churchServiceItemTitle = $readingSection->churchServiceItem?->title;
        if (is_string($churchServiceItemTitle) && trim($churchServiceItemTitle) !== '') {
            return trim($churchServiceItemTitle);
        }

        $sectionTitle = $readingSection->title;
        if (is_string($sectionTitle) && trim($sectionTitle) !== '') {
            return trim($sectionTitle);
        }

        return null;
    }

    /**
     * A reading is in the media when its midpoint falls inside a cut span, so a
     * boundary a second or two off either way still counts.
     *
     * @param  list<array{start: float, end: float}>  $spans
     */
    private function liesWithinSpans(ServiceSection $readingSection, array $spans): bool
    {
        $midpoint = ((float) $readingSection->start_time + (float) $readingSection->end_time) / 2;

        foreach ($spans as $span) {
            if ($midpoint >= $span['start'] && $midpoint <= $span['end']) {
                return true;
            }
        }

        return false;
    }

    private function resolveProcessingLogId(Sermon $sermon): ?int
    {
        $publishedSection = $sermon->publishedServiceSection;

        if ($publishedSection instanceof ServiceSection) {
            return $publishedSection->media_processing_log_id;
        }

        return $this->resolveProcessingLog($sermon)?->id;
    }

    /**
     * @return Collection<int, ServiceSection>
     */
    private function queryReadingSections(int $processingLogId): Collection
    {
        /**
         * Performance Optimization: Limits retrieved columns for the reading sections
         * and their related service items to required fields for reference resolution
         * to reduce memory usage and DB I/O.
         */
        return ServiceSection::query()
            ->select(['id', 'media_processing_log_id', 'church_service_item_id', 'section_type', 'section_order', 'start_time', 'end_time', 'title', 'metadata'])
            ->with('churchServiceItem:id,church_service_id,title')
            ->where('media_processing_log_id', $processingLogId)
            ->where('section_type', ServiceSectionType::BibleReading)
            ->orderBy('section_order')
            ->orderBy('start_time')
            ->get();
    }

    private function resolveProcessingLog(Sermon $sermon): ?MediaProcessingLog
    {
        // Use eager-loaded relationship to avoid N+1 queries on individual sermon pages
        if (is_string($sermon->livestream_processing_id) && $sermon->livestream_processing_id !== '') {
            $processingLog = $sermon->livestreamProcessing;

            if ($processingLog instanceof MediaProcessingLog) {
                return $processingLog;
            }
        }

        return $sermon->latestProcessingLog;
    }

    private function bibleGatewayUrl(string $reference): string
    {
        return 'https://www.biblegateway.com/passage/?search='
            .rawurlencode($reference)
            .'&version=NIVUK';
    }
}
