<?php

declare(strict_types=1);

namespace App\Services;

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

        $metadata = $readingSection->metadataData()->toArray();
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
        /**
         * Performance Optimization: Use relationships instead of manual queries to leverage
         * eager loading and prevent N+1 queries on the sermon page.
         */
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
        $section = ServiceSection::query()
            ->with('churchServiceItem')
            ->where('media_processing_log_id', $processingLogId)
            ->where('section_type', ServiceSectionType::BIBLE_READING)
            ->orderBy('section_order')
            ->orderBy('start_time')
            ->first();

        return $section instanceof ServiceSection ? $section : null;
    }

    private function resolveProcessingLog(Sermon $sermon): ?MediaProcessingLog
    {
        /**
         * Performance Optimization: Use relationships to leverage potential eager loading.
         */
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
