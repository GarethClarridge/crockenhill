<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\OosEmailParseResult;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\InboundEmail;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosEmailParserService;
use App\Services\Song\SongTitleResolver;
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
    public function execute(int $inboundEmailId, ?string $planKey = null): array
    {
        $inboundEmail = InboundEmail::query()->find($inboundEmailId);

        if (! $inboundEmail instanceof InboundEmail) {
            return [];
        }

        $parseData = $this->resolveParseData($inboundEmail);

        // When a plan key is supplied, prefill from exactly that service plan so editing targets
        // one order of a multi-service email; otherwise fall back to the primary/legacy fields.
        $plan = $planKey !== null ? $this->planFromParseData($parseData, $planKey) : null;
        $result = [];

        $resolvedDate = $plan['date'] ?? Arr::get($parseData, 'resolved_date');
        if (is_string($resolvedDate) && $resolvedDate !== '') {
            $result['date'] = $resolvedDate;
        }

        $resolvedService = $plan['service'] ?? Arr::get($parseData, 'resolved_service');
        if (is_string($resolvedService) && in_array($resolvedService, SermonService::values(), true)) {
            $result['service'] = $resolvedService;
        }

        $parsedItems = $plan['items'] ?? Arr::get($parseData, 'items');
        if (is_array($parsedItems)) {
            // Built at most once per prefill, and only if there is a song title to resolve —
            // it loads a lookup over the whole catalogue.
            $songTitleResolver = null;

            $items = collect($parsedItems)
                ->map(function (mixed $item) use (&$songTitleResolver): ?array {
                    return is_array($item) ? $this->itemPayloadFromParsedItem($item, $songTitleResolver) : null;
                })
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
     * @param  array<string, mixed>  $parseData
     * @return array<string, mixed>|null
     */
    private function planFromParseData(array $parseData, string $planKey): ?array
    {
        $plans = Arr::get($parseData, 'service_plans');

        if (! is_array($plans)) {
            return null;
        }

        foreach ($plans as $plan) {
            if (is_array($plan) && ($plan['plan_key'] ?? null) === $planKey) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{key:string,section_type:string,title:string,song_id:int|null}|null
     */
    private function itemPayloadFromParsedItem(array $item, ?SongTitleResolver &$songTitleResolver): ?array
    {
        $title = trim((string) ($item['title'] ?? ''));

        if ($title === '') {
            return null;
        }

        $sectionType = $this->resolveSectionTypeFromParsedItem($item);
        $songId = is_int($item['song_id'] ?? null) ? $item['song_id'] : null;

        return [
            'key' => (string) Str::uuid(),
            'section_type' => $sectionType->value,
            'title' => $title,
            'song_id' => $songId ?? $this->resolveSongId($sectionType, $item, $songTitleResolver),
        ];
    }

    /**
     * The email parser only ever extracts text, so a prefilled song arrives unlinked. Resolving
     * it here means the reviewer confirms a link the catalogue already knows about rather than
     * re-typing every title — the same resolver ChurchServiceSongLinker runs after the save,
     * so the screen agrees with what saving would produce.
     *
     * @param  array<string, mixed>  $item
     */
    private function resolveSongId(
        ServiceSectionType $sectionType,
        array $item,
        ?SongTitleResolver &$songTitleResolver,
    ): ?int {
        if ($sectionType !== ServiceSectionType::Song) {
            return null;
        }

        $searchTitle = $this->firstNonEmptyString([
            $item['openlp_search_title'] ?? null,
            $item['source_title'] ?? null,
            $item['title'] ?? null,
        ]);

        if ($searchTitle === null) {
            return null;
        }

        $songTitleResolver ??= SongTitleResolver::fromDatabase();

        return $songTitleResolver->resolve($searchTitle)?->songId;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
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
            'songs' => ServiceSectionType::Song,
            'bibles' => ServiceSectionType::BibleReading,
            default => ServiceSectionType::inferFromTitle((string) ($item['title'] ?? '')),
        };
    }
}
