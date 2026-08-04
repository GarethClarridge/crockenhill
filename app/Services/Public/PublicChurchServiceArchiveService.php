<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Presenters\SermonViewPresenter;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Song\SongVideoService;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the public service archive.
 *
 * Every eligibility decision is delegated to {@see PublicServiceContentEligibility}
 * so this surface and the public song archive answer "was this sung here?" the
 * same way.
 */
class PublicChurchServiceArchiveService
{
    /**
     * Sort tier for an entry anchored to a detected section: it has a real place
     * in the observed order.
     */
    private const TIER_ANCHORED = 0;

    /**
     * Sort tier for an entry with no detected section, interpolated after the
     * nearest preceding anchor.
     */
    private const TIER_INTERPOLATED = 1;

    public function __construct(
        private readonly PublicServiceContentEligibility $eligibility,
        private readonly SermonExposurePolicy $exposurePolicy,
        private readonly SermonViewPresenter $sermonViewPresenter,
        private readonly SongVideoService $songVideoService,
    ) {}

    /**
     * @return Builder<ChurchService>
     */
    public function query(?int $year = null, ?SermonService $service = null): Builder
    {
        $query = ChurchService::query();

        $this->eligibility->applyDateEligibility($query);
        $this->eligibility->applyHasPublicContent($query);

        return $query
            ->when($year !== null, fn (Builder $yearQuery): Builder => $yearQuery->whereYear('church_services.date', $year))
            ->when($service !== null, fn (Builder $serviceQuery): Builder => $serviceQuery->where('church_services.service', $service))
            ->orderByDesc('church_services.date')
            ->orderBy($this->serviceOrderExpression())
            ->orderByDesc('church_services.id');
    }

    /**
     * @return LengthAwarePaginator<int, ChurchService>
     */
    public function paginate(?int $year = null, ?SermonService $service = null): LengthAwarePaginator
    {
        return $this->query($year, $service)
            ->select(['church_services.id', 'church_services.date', 'church_services.service'])
            ->paginate(12)
            ->withQueryString();
    }

    /**
     * Years that contain at least one publicly visible service, newest first.
     *
     * Resolved in SQL rather than by loading every date: the historic import
     * multiplies this table roughly twenty-fivefold.
     *
     * @return list<int>
     */
    public function years(): array
    {
        /** @var list<int> $years */
        $years = $this->query()
            ->reorder()
            ->selectRaw('DISTINCT YEAR(church_services.date) AS service_year')
            ->orderByDesc('service_year')
            ->pluck('service_year')
            ->map(fn (mixed $year): int => (int) $year)
            ->all();

        return $years;
    }

    /**
     * Resolve a public service by its canonical date/service pair.
     *
     * `church_services_date_service_unique` guarantees the pair identifies at most
     * one row, so this URL shape cannot become ambiguous.
     */
    public function find(Carbon $date, SermonService $service): ChurchService
    {
        $churchService = $this->query()
            ->whereDate('church_services.date', $date->toDateString())
            ->where('church_services.service', $service)
            ->firstOrFail();

        $this->loadPublicGraph($churchService);

        return $churchService;
    }

    /**
     * The public URL for a service, or null when it is not publicly visible.
     *
     * Used by surfaces that already know a service is relevant — a song's usage
     * history, a sermon's page — to link back into the archive without offering a
     * link that would 404. Content eligibility is implied by the caller's context;
     * only the date window is re-checked, so this costs no query.
     */
    public function publicUrlFor(?ChurchService $churchService): ?string
    {
        if (! $churchService instanceof ChurchService) {
            return null;
        }

        if ($churchService->date->startOfDay()->isAfter(now()->startOfDay())) {
            return null;
        }

        $publicFrom = $this->eligibility->publicFrom();

        if ($publicFrom instanceof Carbon && $churchService->date->startOfDay()->isBefore($publicFrom)) {
            return null;
        }

        return route('church.services.show', [
            'date' => $churchService->date->format('Y-m-d'),
            'service' => $churchService->service->value,
        ]);
    }

    /**
     * The public service page a sermon was preached at, or null if there isn't one.
     *
     * Unlike {@see publicUrlFor()} this resolves through the full listing query, so
     * it never links to a service the archive would refuse to serve.
     */
    public function publicUrlForSermon(Sermon $sermon): ?string
    {
        if (! $sermon->service instanceof SermonService) {
            return null;
        }

        $churchService = $this->query()
            ->whereDate('church_services.date', $sermon->date->toDateString())
            ->where('church_services.service', $sermon->service)
            ->select(['church_services.id', 'church_services.date', 'church_services.service'])
            ->first();

        return $this->publicUrlFor($churchService);
    }

    /**
     * Build the publication-safe ordered rows for a service.
     *
     * The returned arrays deliberately contain presentation data only. Source
     * records, review state, confidence, processing diagnostics and storage paths
     * never cross this boundary into a public view.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function publicItems(ChurchService $churchService): Collection
    {
        $this->loadPublicGraph($churchService);

        $sections = $this->publicSections($churchService);
        $anchors = $this->sectionAnchors($churchService, $sections);
        $eligibleSongItemIds = $this->eligibleSongItemIds($churchService);

        $entries = [];

        foreach ($churchService->items as $item) {
            $entry = $this->itemEntry($item, $sections, $anchors, $eligibleSongItemIds);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        foreach ($this->exposableSermons($churchService) as $sermon) {
            $entries[] = $this->sermonEntry($sermon, $sections);
        }

        usort($entries, fn (array $first, array $second): int => $first['sort'] <=> $second['sort']);

        foreach (array_keys($entries) as $index) {
            unset($entries[$index]['sort']);
        }

        return collect($entries);
    }

    /**
     * @param  Collection<int, ServiceSection>  $sections
     * @param  array<int, int>  $anchors
     * @param  array<int, true>  $eligibleSongItemIds
     * @return array<string, mixed>|null
     */
    private function itemEntry(
        ChurchServiceItem $item,
        Collection $sections,
        array $anchors,
        array $eligibleSongItemIds,
    ): ?array {
        $section = $sections->first(
            fn (ServiceSection $candidate): bool => $candidate->church_service_item_id === $item->id
        );

        if ($item->type === 'songs') {
            if (! isset($eligibleSongItemIds[$item->id])) {
                return null;
            }

            return $this->songEntry($item, $section, $this->sortKey($item, $section, $anchors));
        }

        if ($item->type === 'bibles' && filled($item->title)) {
            return [
                'id' => 'scripture-'.$item->id,
                'kind' => 'scripture',
                'title' => $item->title,
                'planned_only' => $section === null,
                'song_url' => null,
                'song_video_url' => null,
                'sermon_view' => null,
                'sort' => $this->sortKey($item, $section, $anchors),
            ];
        }

        return null;
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $sortKey
     * @return array<string, mixed>
     */
    private function songEntry(ChurchServiceItem $item, ?ServiceSection $section, array $sortKey): array
    {
        $song = $item->song;

        return [
            'id' => 'song-'.$item->id,
            'kind' => 'song',
            'title' => $song instanceof Song ? $song->title : ($item->title ?? 'Song'),
            'planned_only' => $section === null,
            'song_url' => $song instanceof Song && filled($song->slug)
                ? route('church.songs.show', $song->slug)
                : null,
            'song_video_url' => $this->songVideoUrl($section),
            'sermon_view' => null,
            'sort' => $sortKey,
        ];
    }

    /**
     * @param  Collection<int, ServiceSection>  $sections
     * @return array<string, mixed>
     */
    private function sermonEntry(Sermon $sermon, Collection $sections): array
    {
        $isTalk = $sermon->content_type === SermonContentType::ChildrensTalk;
        $sectionType = $isTalk ? ServiceSectionType::ChildrensTalk : ServiceSectionType::Sermon;

        $section = $sections->first(
            fn (ServiceSection $candidate): bool => $candidate->section_type === $sectionType
        );

        return [
            'id' => 'sermon-'.$sermon->id,
            'kind' => $isTalk ? 'childrens_talk' : 'sermon',
            'title' => $sermon->title,
            'planned_only' => false,
            'song_url' => null,
            'song_video_url' => null,
            'sermon_view' => $this->sermonViewPresenter->presentForList($sermon),
            // An undetected sermon still belongs on the page. Placing it last is
            // truthful for a service order; a children's talk precedes it.
            'sort' => $section instanceof ServiceSection
                ? [$section->section_order, self::TIER_ANCHORED, 0]
                : [PHP_INT_MAX, self::TIER_ANCHORED, $isTalk ? 0 : 1],
        ];
    }

    /**
     * Position an entry in a single ordering space.
     *
     * Item positions and section orders are different numbering spaces and must
     * never be compared directly. When a processing run detected the item, its
     * `section_order` is the observed order and wins. When it did not, the entry
     * is interpolated immediately after the nearest preceding detected item, so
     * planned-only rows keep their order-of-service sequence without pretending to
     * a detected position.
     *
     * @param  array<int, int>  $anchors  item position => section order
     * @return array{0: int, 1: int, 2: int}
     */
    private function sortKey(ChurchServiceItem $item, ?ServiceSection $section, array $anchors): array
    {
        if ($section instanceof ServiceSection) {
            return [$section->section_order, self::TIER_ANCHORED, $item->position];
        }

        if ($anchors === []) {
            return [$item->position, self::TIER_ANCHORED, $item->position];
        }

        $precedingAnchor = 0;

        foreach ($anchors as $anchorPosition => $anchorOrder) {
            if ($anchorPosition < $item->position && $anchorOrder > $precedingAnchor) {
                $precedingAnchor = $anchorOrder;
            }
        }

        return [$precedingAnchor, self::TIER_INTERPOLATED, $item->position];
    }

    /**
     * Detected item positions mapped to their place in the observed order.
     *
     * @param  Collection<int, ServiceSection>  $sections
     * @return array<int, int>
     */
    private function sectionAnchors(ChurchService $churchService, Collection $sections): array
    {
        $positions = $churchService->items
            ->pluck('position', 'id')
            ->all();

        $anchors = [];

        foreach ($sections as $section) {
            $itemId = $section->church_service_item_id;

            if ($itemId !== null && isset($positions[$itemId])) {
                $anchors[$positions[$itemId]] = $section->section_order;
            }
        }

        return $anchors;
    }

    /**
     * A performance video is public only once its section has been published.
     *
     * This is the same gate the song publication pipeline applies; the section
     * being *detected* is not consent to publish the recording.
     */
    private function songVideoUrl(?ServiceSection $section): ?string
    {
        if (! $section instanceof ServiceSection) {
            return null;
        }

        if ($section->publication_status !== ServiceSectionPublicationStatus::Published) {
            return null;
        }

        $video = $section->songVideos->first(
            fn ($songVideo): bool => filled($songVideo->video_file_path)
        );

        return $video === null ? null : $this->songVideoService->getVideoUrl($video);
    }

    /**
     * The sermon and children's talk recorded for this service's slot.
     *
     * Resolved from the sermons table by date and service, which is how the rest
     * of the application links a service to its teaching. The section's
     * `published_sermon_id` is not usable here: no publication handler is
     * registered for the `sermon` section type, so it is null on every section in
     * production and would hide every sermon in the archive.
     *
     * A service's slot is keyed on (date, service), which is not a foreign key, so
     * this cannot be an Eloquent relation.
     *
     * @return Collection<int, Sermon>
     */
    private function exposableSermons(ChurchService $churchService): Collection
    {
        $contentTypes = $this->eligibility->exposableSermonContentTypes();

        if ($contentTypes === []) {
            return collect();
        }

        return Sermon::query()
            ->where('date', $churchService->date->toDateString())
            ->where('service', $churchService->service)
            ->whereIn('content_type', $contentTypes)
            ->with(['preacherProfile', 'scripturePassage'])
            ->orderBy('content_type')
            ->get()
            ->filter(fn (Sermon $sermon): bool => $this->exposurePolicy->shouldExposeOnChurchService($sermon))
            ->values();
    }

    /**
     * @return array<int, true>
     */
    private function eligibleSongItemIds(ChurchService $churchService): array
    {
        $query = ChurchServiceItem::query()
            ->join('church_services', 'church_services.id', '=', 'church_service_items.church_service_id')
            ->where('church_service_items.church_service_id', $churchService->id)
            ->where('church_service_items.type', 'songs')
            ->whereNull('church_service_items.deleted_at');

        $this->eligibility->applySongItemEligibility($query);

        /** @var list<int> $ids */
        $ids = $query->pluck('church_service_items.id')->all();

        return array_fill_keys($ids, true);
    }

    /**
     * Sections from the runs that still represent this service.
     *
     * @return Collection<int, ServiceSection>
     */
    private function publicSections(ChurchService $churchService): Collection
    {
        return $churchService->mediaProcessingLogs
            ->flatMap(fn ($run): Collection => $run->serviceSections)
            ->sortBy(fn (ServiceSection $section): array => [$section->section_order, $section->id])
            ->values();
    }

    /**
     * Eager-load everything the public view needs.
     *
     * Called from `publicItems()` rather than only from `find()`, so a caller that
     * built the model another way cannot lazy-load `serviceSections` without the
     * `notSuperseded` scope and leak sections from a superseded run.
     */
    private function loadPublicGraph(ChurchService $churchService): void
    {
        $churchService->loadMissing([
            'items' => fn ($query) => $query
                ->whereIn('type', PublicServiceContentEligibility::PUBLIC_ITEM_TYPES)
                ->with(['song:id,slug,title'])
                ->orderBy('position')
                ->orderBy('id'),
            'mediaProcessingLogs' => fn ($query) => $query
                ->notSuperseded()
                ->with([
                    'serviceSections' => fn ($sectionQuery) => $sectionQuery
                        ->notSuperseded()
                        ->with(['songVideos'])
                        ->orderBy('section_order')
                        ->orderBy('id'),
                ]),
        ]);
    }

    /**
     * Order morning before evening before other, taking the sequence from the enum
     * rather than restating it in SQL, so adding a case cannot silently misorder
     * the archive.
     *
     * The values are interpolated rather than bound because `orderBy()` takes no
     * bindings. That is safe here and only here: they are enum-declared constants
     * in this repository, never request input.
     */
    private function serviceOrderExpression(): Expression
    {
        $values = implode(', ', array_map(
            fn (SermonService $service): string => "'".$service->value."'",
            SermonService::cases(),
        ));

        return DB::raw('FIELD(church_services.service, '.$values.')');
    }
}
