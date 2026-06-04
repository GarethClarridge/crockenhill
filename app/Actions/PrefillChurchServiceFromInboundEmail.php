<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\OosEmailParseResult;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\InboundEmail;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosEmailParserService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PrefillChurchServiceFromInboundEmail
{
    public function __construct(
        private readonly InboundEmailImportService $importService,
        private readonly OosEmailParserService $parserService,
    ) {}

    /**
     * Build form prefill data from a stored or freshly-parsed inbound email.
     *
     * Returns a partial form state array with optional `date`, `service`, and `items` keys.
     * Callers should only apply keys that are present and non-empty.
     *
     * @return array{date?:string,service?:string,items?:array<int,array{key:string,section_type:string,title:string,song_id:int|null}>}
     */
    public function execute(int $inboundEmailId): array
    {
        $inboundEmail = InboundEmail::query()->find($inboundEmailId);

        if (! $inboundEmail instanceof InboundEmail) {
            return [];
        }

        $parseData = $this->resolveParseData($inboundEmail);
        $result = [];

        $resolvedDate = Arr::get($parseData, 'resolved_date');
        if (is_string($resolvedDate) && $resolvedDate !== '') {
            $result['date'] = $resolvedDate;
        }

        $resolvedService = Arr::get($parseData, 'resolved_service');
        if (is_string($resolvedService) && in_array($resolvedService, SermonService::values(), true)) {
            $result['service'] = $resolvedService;
        }

        $parsedItems = Arr::get($parseData, 'items');
        if (is_array($parsedItems)) {
            $items = collect($parsedItems)
                ->map(fn (mixed $item): ?array => is_array($item) ? $this->itemPayloadFromParsedItem($item) : null)
                ->filter()
                ->values()
                ->all();

            if ($items !== []) {
                $result['items'] = $items;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveParseData(InboundEmail $inboundEmail): array
    {
        $parseResult = $this->importService->storedParseResult($inboundEmail);

        if (! $parseResult instanceof OosEmailParseResult) {
            $parseResult = $this->parserService->parse($inboundEmail);
            $this->importService->storeParseResult($inboundEmail, $parseResult);
        }

        $refreshed = $inboundEmail->fresh();
        $metadata = $refreshed instanceof InboundEmail && is_array($refreshed->processing_metadata)
            ? $refreshed->processing_metadata
            : [];

        return is_array(Arr::get($metadata, 'parsing')) ? Arr::get($metadata, 'parsing') : [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{key:string,section_type:string,title:string,song_id:int|null}|null
     */
    private function itemPayloadFromParsedItem(array $item): ?array
    {
        $title = trim((string) ($item['title'] ?? ''));

        if ($title === '') {
            return null;
        }

        return [
            'key' => (string) Str::uuid(),
            'section_type' => $this->resolveSectionTypeFromParsedItem($item)->value,
            'title' => $title,
            'song_id' => is_int($item['song_id'] ?? null) ? $item['song_id'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveSectionTypeFromParsedItem(array $item): ServiceSectionType
    {
        $sectionType = $item['section_type'] ?? null;

        if (is_string($sectionType)) {
            $resolved = ServiceSectionType::tryFrom($sectionType);

            if ($resolved instanceof ServiceSectionType) {
                return $resolved;
            }
        }

        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $metadataType = $metadata['section_type'] ?? $metadata['email_type'] ?? null;

        if (is_string($metadataType)) {
            $resolved = ServiceSectionType::tryFrom($metadataType);

            if ($resolved instanceof ServiceSectionType) {
                return $resolved;
            }
        }

        return match ($item['type'] ?? null) {
            'songs' => ServiceSectionType::SONG,
            'bibles' => ServiceSectionType::BIBLE_READING,
            default => ServiceSectionType::inferFromTitle((string) ($item['title'] ?? '')),
        };
    }
}
