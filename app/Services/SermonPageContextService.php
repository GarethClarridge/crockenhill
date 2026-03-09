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

        $metadata = is_array($readingSection->metadata) ? $readingSection->metadata : [];
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
        $publishedSection = $sermon->publishedServiceSection()
            ->with(['processingLog.serviceSections.churchServiceItem'])
            ->first();

        if ($publishedSection instanceof ServiceSection) {
            return $this->findReadingSection($publishedSection->processingLog->serviceSections);
        }

        $processingLog = $this->resolveProcessingLog($sermon);

        if (! $processingLog instanceof MediaProcessingLog) {
            return null;
        }

        $processingLog->loadMissing('serviceSections.churchServiceItem');

        return $this->findReadingSection($processingLog->serviceSections);
    }

    private function resolveProcessingLog(Sermon $sermon): ?MediaProcessingLog
    {
        if (is_string($sermon->livestream_processing_id) && $sermon->livestream_processing_id !== '') {
            $processingLog = MediaProcessingLog::query()
                ->where('processing_id', $sermon->livestream_processing_id)
                ->first();

            if ($processingLog instanceof MediaProcessingLog) {
                return $processingLog;
            }
        }

        return $sermon->processingLogs()
            ->latest('id')
            ->first();
    }

    /**
     * @param  iterable<int, ServiceSection>|null  $sections
     */
    private function findReadingSection(?iterable $sections): ?ServiceSection
    {
        if ($sections === null) {
            return null;
        }

        $candidates = collect($sections)
            ->filter(fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::BIBLE_READING)
            ->sortBy([
                ['section_order', 'asc'],
                ['start_time', 'asc'],
            ]);

        $section = $candidates->first();

        return $section instanceof ServiceSection ? $section : null;
    }

    private function bibleGatewayUrl(string $reference): string
    {
        return 'https://www.biblegateway.com/passage/?search='
            .rawurlencode($reference)
            .'&version=NIVUK';
    }
}
