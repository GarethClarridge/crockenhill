<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\OosEmailParseResult;
use App\Data\SongTitleMatch;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\InboundEmail;
use App\Models\Song;
use App\Services\ChurchService\ChurchServiceSongLinker;
use App\Services\ChurchService\ServiceItemTitleCleaner;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosEmailParserService;
use App\Services\Song\SongTitleResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PrefillChurchServiceFromInboundEmail
{
    private const AUDITED_MATCH_TYPES = [
        SongTitleMatch::TYPE_FIRST_LINE,
        SongTitleMatch::TYPE_FUZZY,
        SongTitleMatch::TYPE_HYMNBOOK_ABSENT,
    ];

    public function __construct(
        private readonly InboundEmailImportService $importService,
        private readonly OosEmailParserService $parserService,
        private readonly ServiceItemTitleCleaner $titleCleaner,
    ) {}

    /**
     * Build form prefill data from a stored or freshly-parsed inbound email.
     *
     * Returns a partial form state array with optional `date`, `service`, and `items` keys.
     * Callers should only apply keys that are present and non-empty.
     *
     * @return array{date?:string,service?:string,items?:array<int,array{key:string,section_type:string,title:string,source_title:?string,song_id:int|null,inferred_song_link:bool}>}
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
        if ($planKey !== null) {
            $plan = $this->planFromParseData($parseData, $planKey);

            if ($plan === null) {
                return [];
            }
        } else {
            $plan = null;
        }

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
                $result['items'] = $this->withCatalogueSongTitles($items);
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
     * The title is cleaned here as well as in the parser, so a plan parsed before cleaning
     * existed still reviews with a readable title instead of waiting on a re-parse. Cleaning
     * an already-clean title is a no-op, so running it twice costs nothing.
     *
     * @param  array<string, mixed>  $item
     * @return array{key:string,section_type:string,title:string,source_title:?string,song_id:int|null,inferred_song_link:bool}|null
     */
    private function itemPayloadFromParsedItem(array $item, ?SongTitleResolver &$songTitleResolver): ?array
    {
        $storedTitle = trim((string) ($item['title'] ?? ''));

        if ($storedTitle === '') {
            return null;
        }

        $sectionType = $this->resolveSectionTypeFromParsedItem($item);
        $songId = is_int($item['song_id'] ?? null) ? $item['song_id'] : null;
        $songMatch = $songId === null
            ? $this->resolveSongMatch($sectionType, $item, $songTitleResolver)
            : null;
        $sourceTitle = $this->firstNonEmptyString([$item['source_title'] ?? null, $storedTitle]);

        return [
            'key' => (string) Str::uuid(),
            'section_type' => $sectionType->value,
            'title' => $this->titleCleaner->displayTitle($storedTitle, $sectionType),
            // Always set: a prefilled item came from an email, so it always has a raw line,
            // and pinning it here is what lets the reviewer edit the title freely.
            'source_title' => $sourceTitle === null ? null : trim($sourceTitle),
            'song_id' => $songId ?? $songMatch?->songId,
            'inferred_song_link' => $songMatch instanceof SongTitleMatch
                && in_array($songMatch->matchType, self::AUDITED_MATCH_TYPES, true),
        ];
    }

    /**
     * Show a matched song under its catalogue title rather than the email's rendering of it.
     *
     * This is a preview, not a separate rule: {@see ChurchServiceSongLinker} titles song items
     * from the catalogue on every path, so what the reviewer reads here is what saving writes —
     * and what an auto-import of the same email would have written unattended. The email's own
     * wording is not lost; it stays on the item as `source_title`.
     *
     * @param  array<int, array{key:string,section_type:string,title:string,source_title:?string,song_id:int|null,inferred_song_link:bool}>  $items
     * @return array<int, array{key:string,section_type:string,title:string,source_title:?string,song_id:int|null,inferred_song_link:bool}>
     */
    private function withCatalogueSongTitles(array $items): array
    {
        $songIds = collect($items)
            ->filter(static fn (array $item): bool => $item['section_type'] === ServiceSectionType::Song->value)
            ->pluck('song_id')
            ->filter(static fn (?int $songId): bool => $songId !== null)
            ->unique()
            ->values()
            ->all();

        if ($songIds === []) {
            return $items;
        }

        $catalogueTitles = Song::query()->whereKey($songIds)->pluck('title', 'id');

        foreach ($items as $index => $item) {
            if ($item['section_type'] !== ServiceSectionType::Song->value || $item['song_id'] === null) {
                continue;
            }

            $catalogueTitle = $catalogueTitles->get($item['song_id']);

            if (is_string($catalogueTitle) && trim($catalogueTitle) !== '') {
                $items[$index]['title'] = $catalogueTitle;
            }
        }

        return $items;
    }

    /**
     * The email parser only ever extracts text, so a prefilled song arrives unlinked. Resolving
     * it here means the reviewer confirms a link the catalogue already knows about rather than
     * re-typing every title — the same resolver ChurchServiceSongLinker runs after the save,
     * so the screen agrees with what saving would produce.
     *
     * @param  array<string, mixed>  $item
     */
    private function resolveSongMatch(
        ServiceSectionType $sectionType,
        array $item,
        ?SongTitleResolver &$songTitleResolver,
    ): ?SongTitleMatch {
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

        return $songTitleResolver->resolve($searchTitle);
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
