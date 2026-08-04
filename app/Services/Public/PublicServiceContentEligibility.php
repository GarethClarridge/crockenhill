<?php

declare(strict_types=1);

namespace App\Services\Public;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Services\Sermon\SermonExposurePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * The single boundary defining what of a church service may be shown publicly.
 *
 * Both the public song archive and the public service archive resolve eligibility
 * here, so a song can never appear in one surface and be missing from the other.
 * Before this class existed the two surfaces each carried their own rule and
 * disagreed: song usage counted an item under the Phase 6.1 policy while service
 * history counted it only once a section reached `published`.
 */
class PublicServiceContentEligibility
{
    /**
     * Order-of-service item types that are safe to publish verbatim.
     *
     * Deliberately narrow. `custom`, `images`, `presentations` and `media` items
     * carry projector filenames ("Notices2024Looped.pptx"), operator cues
     * ("scenechange:Lecturn") and pastoral notices naming individuals, none of
     * which are publication-safe. Only songs and Bible references are.
     */
    public const PUBLIC_ITEM_TYPES = ['songs', 'bibles'];

    public function __construct(
        private readonly SermonExposurePolicy $exposurePolicy,
    ) {}

    /**
     * The earliest date the public archive will serve, or null for no bound.
     */
    public function publicFrom(): ?Carbon
    {
        $configured = config('church.services.public_from');

        if (! is_string($configured) || $configured === '') {
            return null;
        }

        return Carbon::parse($configured)->startOfDay();
    }

    /**
     * Restrict a church service query to the services the public may see at all.
     *
     * Date eligibility only: a service still needs public *content* to be worth
     * a page, which `applyHasPublicContent()` decides.
     *
     * @param  Builder<ChurchService>  $query
     * @return Builder<ChurchService>
     */
    public function applyDateEligibility(Builder $query): Builder
    {
        $query->whereDate('church_services.date', '<=', now()->toDateString());

        $publicFrom = $this->publicFrom();

        if ($publicFrom instanceof Carbon) {
            $query->whereDate('church_services.date', '>=', $publicFrom->toDateString());
        }

        return $query;
    }

    /**
     * Restrict a church service query to services that would render something.
     *
     * Without this the archive lists and sitemaps every service it holds, the
     * overwhelming majority of which have no publication-safe content and render
     * an empty state.
     *
     * @param  Builder<ChurchService>  $query
     * @return Builder<ChurchService>
     */
    public function applyHasPublicContent(Builder $query): Builder
    {
        return $query->where(function (Builder $contentQuery): void {
            $contentQuery
                ->whereExists(fn (QueryBuilder $itemQuery) => $this->publicItemExistsQuery($itemQuery))
                ->orWhereExists(fn (QueryBuilder $sermonQuery) => $this->exposableSermonExistsQuery($sermonQuery));
        });
    }

    /**
     * Apply the Phase 6.1 song eligibility policy to a church service items query.
     *
     * An order-of-service item stays eligible unless a completed livestream run
     * exists for its service, in which case the livestream must have confirmed the
     * song. Failed, pending, in-progress and non-livestream runs leave the item
     * eligible.
     *
     * The query must expose both `church_service_items` and `church_services` —
     * either by joining them, or by being a subquery correlated to an outer
     * `church_services`.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public function applySongItemEligibility(Builder|QueryBuilder $query): void
    {
        $query->where(function (Builder|QueryBuilder $eligibility): void {
            $eligibility
                ->whereExists(function (QueryBuilder $logQuery): void {
                    $this->completedLivestreamQuery($logQuery)
                        ->whereExists(function (QueryBuilder $sectionQuery): void {
                            $sectionQuery->selectRaw('1')
                                ->from('service_sections')
                                ->whereColumn('service_sections.media_processing_log_id', 'media_processing_logs.id')
                                ->whereColumn('service_sections.church_service_item_id', 'church_service_items.id')
                                ->where('service_sections.section_type', ServiceSectionType::Song->value)
                                ->where('service_sections.song_match_type', ServiceSectionSongMatchType::Confirmed->value);
                        });
                })
                ->orWhereNotExists(fn (QueryBuilder $logQuery) => $this->completedLivestreamQuery($logQuery));
        });
    }

    /**
     * Whether a sermon or children's talk may be surfaced on a public service page.
     */
    public function allowsSermonContentType(SermonContentType $contentType): bool
    {
        return $this->exposurePolicy->exposesContentTypeOnChurchService($contentType);
    }

    /**
     * The sermon content types a public service page may link to.
     *
     * @return list<SermonContentType>
     */
    public function exposableSermonContentTypes(): array
    {
        return array_values(array_filter(
            SermonContentType::cases(),
            fn (SermonContentType $contentType): bool => $this->allowsSermonContentType($contentType),
        ));
    }

    /**
     * `EXISTS` fragment: this service has at least one publication-safe item.
     */
    private function publicItemExistsQuery(QueryBuilder $query): QueryBuilder
    {
        return $query->selectRaw('1')
            ->from('church_service_items')
            ->whereColumn('church_service_items.church_service_id', 'church_services.id')
            ->whereNull('church_service_items.deleted_at')
            ->where(function (QueryBuilder $typeQuery): void {
                $typeQuery
                    ->where('church_service_items.type', 'bibles')
                    ->orWhere(function (QueryBuilder $songQuery): void {
                        $songQuery->where('church_service_items.type', 'songs');
                        $this->applySongItemEligibility($songQuery);
                    });
            });
    }

    /**
     * `EXISTS` fragment: this service's date/service slot has an exposable sermon.
     */
    private function exposableSermonExistsQuery(QueryBuilder $query): QueryBuilder
    {
        return $query->selectRaw('1')
            ->from('sermons')
            ->whereColumn('sermons.date', 'church_services.date')
            ->whereColumn('sermons.service', 'church_services.service')
            ->whereIn('sermons.content_type', array_map(
                fn (SermonContentType $contentType): string => $contentType->value,
                $this->exposableSermonContentTypes(),
            ));
    }

    private function completedLivestreamQuery(QueryBuilder $query): QueryBuilder
    {
        return $query->selectRaw('1')
            ->from('media_processing_logs')
            ->whereColumn('media_processing_logs.church_service_id', 'church_services.id')
            ->where('media_processing_logs.processing_type', MediaType::Livestream->value)
            ->where('media_processing_logs.status', ProcessingStatus::Completed->value);
    }
}
