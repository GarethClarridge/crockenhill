<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;

class SermonPageContextService
{
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

    private function resolveReadingReference(Sermon $sermon): ?string
    {
        $readingSection = $this->resolveReadingSection($sermon);

        if (! $readingSection instanceof ServiceSection) {
            return null;
        }

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

    private function resolveReadingSection(Sermon $sermon): ?ServiceSection
    {
        $publishedSection = $sermon->publishedServiceSection;

        if ($publishedSection instanceof ServiceSection) {
            return $this->queryReadingSection($publishedSection->media_processing_log_id);
        }

        $processingLog = $this->resolveProcessingLog($sermon);

        if (! $processingLog instanceof MediaProcessingLog) {
            return null;
        }

        return $this->queryReadingSection($processingLog->id);
    }

    private function queryReadingSection(int $processingLogId): ?ServiceSection
    {
        /**
         * Performance Optimization: Limits retrieved columns for the reading section
         * and its related service item to required fields for reference resolution
         * to reduce memory usage and DB I/O.
         */
        $section = ServiceSection::query()
            ->select(['id', 'media_processing_log_id', 'church_service_item_id', 'section_type', 'section_order', 'start_time', 'title', 'metadata'])
            ->with('churchServiceItem:id,church_service_id,title')
            ->where('media_processing_log_id', $processingLogId)
            ->where('section_type', ServiceSectionType::BibleReading)
            ->orderBy('section_order')
            ->orderBy('start_time')
            ->first();

        return $section instanceof ServiceSection ? $section : null;
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
