<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ChurchServiceProposalStatus;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\SpeakerProfile;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Processing\MediaProcessingIdentityResolver;
use App\Support\ChurchServiceRunMatcher;
use App\Support\ServiceSectionConfidence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ServiceReviewDashboardQuery
{
    private const LAZY_CHUNK_SIZE = 100;

    /** @var array<string, ChurchService|null> */
    private array $serviceKeyLookupCache = [];

    private ?bool $hasActiveSpeakerProfiles = null;

    public function __construct(
        private readonly MediaProcessingIdentityResolver $identityResolver,
        private readonly ChurchServiceRunMatcher $runMatcher,
    ) {}

    /**
     * @param  int|null  $sectionLimit  When set, at most this many flagged sections are
     *                                  collected (newest first), the backlog is iterated
     *                                  lazily, and section-less needs_review service groups
     *                                  are omitted — the capped inbox path, which only reads
     *                                  section entries. Null keeps the full, exact result the
     *                                  dashboard summary and counts rely on.
     * @return array<int, array{
     *     key:string,
     *     date:string|null,
     *     date_label:string,
     *     service_label:string,
     *     service_enum:SermonService|null,
     *     sort_date:string,
     *     service_sort:int,
     *     service:ChurchService|null,
     *     pending_approval_count:int,
     *     batch_ready_count:int,
     *     batch_blocked_count:int,
     *     sections:array<int, array{
     *         section:ServiceSection,
     *         reasons:array<int, array{key:string,label:string,classes:string}>,
     *         review_reason:string|null,
     *         audio_url:string|null,
     *         video_url:string|null,
     *         manual_edit_url:string|null
     *     }>
     * }>
     */
    public function reviewGroups(?int $sectionLimit = null): array
    {
        if ($sectionLimit === null) {
            $reviewServices = ChurchService::query()
                ->where('needs_review', true)
                ->orderByDesc('date')
                ->orderBy('service')
                ->get();

            $sections = $this->reviewSections();
            $serviceLookup = $this->serviceLookup($sections, $reviewServices);
        } else {
            // Capped callers (the inbox) only consume section entries, so the
            // needs_review service seeding is skipped too — hydrating every
            // flagged service would unbound the render cost the cap exists to
            // contain. Sections iterate lazily and group services resolve per
            // key on demand instead of via the upfront identity scan.
            $reviewServices = new EloquentCollection;
            $sections = $this->reviewSectionsQuery()->lazy(self::LAZY_CHUNK_SIZE);
            $serviceLookup = [];
        }

        $groups = [];
        $flaggedSectionCount = 0;

        foreach ($reviewServices as $service) {
            $key = $this->serviceKey($service->date->toDateString(), $service->service);
            $groups[$key] = $this->makeGroup(
                key: $key,
                date: $service->date,
                service: $service->service,
                serviceModel: $service
            );
        }

        foreach ($sections as $section) {
            if ($sectionLimit !== null && $flaggedSectionCount >= $sectionLimit) {
                break;
            }

            $reasons = $this->reviewReasons($section);
            if ($reasons === []) {
                continue;
            }

            $context = $this->resolveGroupContext($section, $serviceLookup, lookupOnMiss: $sectionLimit !== null);
            $key = $context['key'];

            if (! array_key_exists($key, $groups)) {
                $groups[$key] = $this->makeGroup(
                    key: $key,
                    date: $context['date'],
                    service: $context['service'],
                    serviceModel: $context['service_model']
                );
            } elseif (
                ! ($groups[$key]['service'] instanceof ChurchService)
                && $context['service_model'] instanceof ChurchService
            ) {
                $groups[$key]['service'] = $context['service_model'];
            }

            $serviceModel = $groups[$key]['service'];
            $groups[$key]['sections'][] = [
                'section' => $section,
                'reasons' => $reasons,
                'review_reason' => $this->reviewReasonLabel($section),
                'audio_url' => $this->assetUrl($section, 'audio', $section->extracted_audio_path),
                'video_url' => $this->assetUrl($section, 'video', $section->extracted_video_path),
                'manual_edit_url' => $serviceModel instanceof ChurchService
                    ? route('admin.services.show', $serviceModel).'?edit=1'
                    : null,
            ];

            $flaggedSectionCount++;
        }

        $groups = array_values($groups);

        foreach ($groups as &$group) {
            usort($group['sections'], function (array $left, array $right): int {
                $sectionCompare = $left['section']->start_time <=> $right['section']->start_time;

                if ($sectionCompare !== 0) {
                    return $sectionCompare;
                }

                $orderCompare = $left['section']->section_order <=> $right['section']->section_order;
                if ($orderCompare !== 0) {
                    return $orderCompare;
                }

                return $left['section']->id <=> $right['section']->id;
            });

            $group['pending_approval_count'] = collect($group['sections'])
                ->filter(fn (array $entry): bool => $entry['section']->publication_status === ServiceSectionPublicationStatus::PendingApproval)
                ->count();
            $group['batch_ready_count'] = collect($group['sections'])
                ->filter(function (array $entry): bool {
                    $section = $entry['section'];

                    return $section->publication_status === ServiceSectionPublicationStatus::PendingApproval
                        && $this->batchApprovalSkipReason($section) === null;
                })
                ->count();
            $group['batch_blocked_count'] = max(0, $group['pending_approval_count'] - $group['batch_ready_count']);
        }
        unset($group);

        usort($groups, function (array $left, array $right): int {
            $dateCompare = strcmp($right['sort_date'], $left['sort_date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return $left['service_sort'] <=> $right['service_sort'];
        });

        return $groups;
    }

    /**
     * @param  array<int, array{
     *     service:ChurchService|null,
     *     sections:array<int, array{reasons:array<int, array{key:string}>}>
     * }>  $groups
     * @return array{service_groups:int,sections:int,services_needing_review:int,pending_approvals:int,pending_merges:int}
     */
    public function summary(array $groups): array
    {
        $servicesNeedingReview = collect($groups)
            ->filter(fn (array $group): bool => $group['service']?->needs_review === true)
            ->count();

        $sections = collect($groups)
            ->sum(fn (array $group): int => count($group['sections']));

        $pendingApprovals = collect($groups)
            ->sum(fn (array $group): int => collect($group['sections'])
                ->filter(fn (array $entry): bool => collect($entry['reasons'])->contains('key', 'pending_approval'))
                ->count());

        $pendingMerges = $this->pendingMergeCount();

        return [
            'service_groups' => count($groups),
            'sections' => $sections,
            'services_needing_review' => $servicesNeedingReview,
            'pending_approvals' => $pendingApprovals,
            'pending_merges' => $pendingMerges,
        ];
    }

    /**
     * Count of review-candidate sections, derived from the same seven-reason
     * predicate that builds reviewGroups() — never a cheaper SQL approximation,
     * so the hub strip, review inbox, and members-home badge cannot disagree.
     *
     * Performance Optimization: Uses a targeted count query on the base predicate
     * to avoid the expensive grouping and relationship hydration of reviewGroups().
     */
    public function reviewCandidateSectionCount(): int
    {
        return $this->reviewCandidateSectionsBaseQuery()->count();
    }

    /**
     * Services awaiting a merge decision, counted once each. A service can carry both
     * a legacy structure merge and pending evidence proposals; adding the two totals
     * would count it twice and mix services with proposals in one figure.
     */
    public function pendingMergeCount(): int
    {
        return ChurchService::query()
            ->where(fn (Builder $query): Builder => $query
                ->whereNotNull('pending_structure_merge_source')
                ->orWhereHas(
                    'mergeProposals',
                    fn (Builder $proposals): Builder => $proposals->where('status', ChurchServiceProposalStatus::Pending),
                ))
            ->count();
    }

    /**
     * Pending sections scoped to a service via ChurchServiceRunMatcher's
     * three match paths (contract C2), so batch approval covers exactly the
     * sections the workbench counts — including repaired runs matched only
     * through fallback processing ids.
     *
     * @return EloquentCollection<int, ServiceSection>
     */
    public function pendingPublicationSectionsForService(ChurchService $service): EloquentCollection
    {
        $fallbackProcessingIds = $this->runMatcher->fallbackProcessingIdsForService($service);

        return ServiceSection::query()
            ->with([
                'processingLog:id,processing_id,extracted_date,extracted_service,processing_metadata,church_service_id',
                'processingLog.churchService:id,date,service,needs_review',
                'churchServiceItem:id,church_service_id,title,song_id,type',
                'churchServiceItem.churchService:id,date,service,needs_review',
            ])
            ->where('publication_status', ServiceSectionPublicationStatus::PendingApproval->value)
            ->whereDoesntHave('processingLog', fn (Builder $query): Builder => $query->whereNotNull('superseded_at'))
            ->where(function (Builder $query) use ($service, $fallbackProcessingIds): void {
                $query->whereIn('media_processing_log_id', MediaProcessingLog::query()
                    ->select('id')
                    ->notSuperseded()
                    ->tap(fn ($query) => $this->runMatcher->applyMatchClauses($query, $service, $fallbackProcessingIds)))
                    ->orWhereHas('churchServiceItem', fn (Builder $query): Builder => $query->where('church_service_id', $service->id));
            })
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Review-candidate sections scoped to a service via the same run/item
     * matching paths used by the workbench and batch publication actions.
     *
     * @return EloquentCollection<int, ServiceSection>
     */
    public function reviewSectionsForService(ChurchService $service): EloquentCollection
    {
        $fallbackProcessingIds = $this->runMatcher->fallbackProcessingIdsForService($service);

        return $this->reviewSectionsQuery()
            ->where(function (Builder $query) use ($service, $fallbackProcessingIds): void {
                $query->whereIn('media_processing_log_id', MediaProcessingLog::query()
                    ->select('id')
                    ->tap(fn ($query) => $this->runMatcher->applyMatchClauses($query, $service, $fallbackProcessingIds)))
                    ->orWhereHas('churchServiceItem', fn (Builder $query): Builder => $query->where('church_service_id', $service->id));
            })
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return EloquentCollection<int, ServiceSection>
     */
    public function manualReviewSectionsForService(ChurchService $service): EloquentCollection
    {
        return $this->reviewSectionsForService($service)
            ->filter(fn (ServiceSection $section): bool => collect($this->reviewReasons($section))
                ->reject(static fn (array $reason): bool => $reason['key'] === 'pending_approval')
                ->isNotEmpty())
            ->values();
    }

    /**
     * @return array<int, array{key:string,label:string,classes:string}>
     */
    public function reviewReasons(ServiceSection $section): array
    {
        $reasons = [];

        if ($section->needs_manual_review) {
            $reasons[] = [
                'key' => 'needs_manual_review',
                'label' => 'Manual review',
                'classes' => 'bg-rose-100 text-rose-800',
            ];
        }

        if (
            $section->section_type === ServiceSectionType::ChildrensTalk
            && $section->publicationChildrensTalkSpeaker() === null
            && $section->predictedChildrensTalkSpeaker() !== null
            && $this->hasActiveSpeakerProfiles()
        ) {
            $reasons[] = [
                'key' => 'speaker_review',
                'label' => 'Speaker review',
                'classes' => 'bg-cbc-teal-light/15 text-cbc-teal-dark',
            ];
        }

        if ($section->publication_status === ServiceSectionPublicationStatus::PendingApproval) {
            $reasons[] = [
                'key' => 'pending_approval',
                'label' => 'Pending approval',
                'classes' => 'bg-amber-100 text-amber-800',
            ];
        }

        $songPublicationReview = $this->songPublicationReviewReason($section);

        if ($songPublicationReview !== null) {
            $reasons[] = $songPublicationReview;
        }

        $sermonBoundaryReview = $this->sermonBoundaryReviewReason($section);

        if ($sermonBoundaryReview !== null) {
            $reasons[] = $sermonBoundaryReview;
        }

        if (
            $section->section_type->requiresStructuralUncertaintyReview()
            && $section->confidence !== null
            && $section->confidence < ServiceSectionConfidence::HIGH_THRESHOLD
            && ! $this->hasManualConfirmation($section)
            && ! $this->isSuspectedBenediction($section)
            // A confirmed song match has already cleared the transcript-match
            // confidence bar, so the low structural-placement score is not, on
            // its own, a review trigger (mirrors the base query below).
            && ! $section->hasConfirmedSongMatch()
        ) {
            $reasons[] = [
                'key' => 'low_confidence',
                'label' => 'Low confidence',
                'classes' => 'bg-slate-200 text-slate-800',
            ];
        }

        if ($section->hasInferredSongMatch() && ! $this->hasSongMatchReview($section)) {
            $reasons[] = [
                'key' => 'inferred_song_label',
                'label' => 'Inferred song label',
                'classes' => 'bg-violet-100 text-violet-800',
            ];
        }

        if ($section->hasUnmatchedSongMatch() && ! $this->hasSongMatchReview($section)) {
            $reasons[] = [
                'key' => 'unmatched_song',
                'label' => 'Unmatched song',
                'classes' => 'bg-sky-100 text-sky-800',
            ];
        }

        $reviewFlags = $section->metadata['review_flags'] ?? [];

        if (is_array($reviewFlags) && in_array('heuristic_demotion', $reviewFlags, true)) {
            $reasons[] = [
                'key' => 'heuristic_demotion',
                'label' => 'Heuristic classification',
                'classes' => 'bg-indigo-100 text-indigo-800',
            ];
        }

        return $reasons;
    }

    /**
     * Keep the reason recorded by SongPublicationHandler visible on every
     * review surface. A generic pending-approval label is not enough to make
     * an informed batch-or-individual review decision.
     *
     * @return array{key:string,label:string,classes:string}|null
     */
    private function songPublicationReviewReason(ServiceSection $section): ?array
    {
        $review = data_get($section->metadata?->toArray() ?? [], 'song_publication_review');
        $reasons = is_array($review) && is_array($review['reasons'] ?? null)
            ? $review['reasons']
            : [];
        $details = [];

        foreach ($reasons as $reason) {
            if (! is_array($reason)) {
                continue;
            }

            $detail = $reason['detail'] ?? null;

            if (is_string($detail) && trim($detail) !== '') {
                $details[] = trim($detail);
            }
        }

        if ($details === []) {
            return null;
        }

        return [
            'key' => 'song_publication_review',
            'label' => 'Song boundary review: '.implode(' ', $details),
            'classes' => 'bg-amber-100 text-amber-800',
        ];
    }

    /**
     * Surface the corroborated sermon-boundary transition that stopped
     * extraction, so a reviewer can distinguish it from generic structure
     * uncertainty.
     *
     * @return array{key:string,label:string,classes:string}|null
     */
    private function sermonBoundaryReviewReason(ServiceSection $section): ?array
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $flags = is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [];
        $evidence = is_array($metadata['sermon_boundary'] ?? null) ? $metadata['sermon_boundary'] : [];

        if (! in_array(ServiceStructureValidator::FLAG_SERMON_BOUNDARY_MATERIAL_RISK, $flags, true)
            && ! in_array(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $flags, true)
        ) {
            return null;
        }

        $details = [];
        $risks = is_array($evidence['risks'] ?? null) ? $evidence['risks'] : [];

        foreach ($risks as $risk) {
            if (is_array($risk) && is_string($risk['detail'] ?? null) && trim($risk['detail']) !== '') {
                $details[] = trim($risk['detail']);
            }
        }

        return [
            'key' => 'sermon_boundary_review',
            'label' => 'Sermon boundary review'.($details === [] ? '' : ': '.implode(' ', $details)),
            'classes' => 'bg-rose-100 text-rose-800',
        ];
    }

    public function isReviewCandidate(ServiceSection $section): bool
    {
        return $this->reviewReasons($section) !== [];
    }

    /**
     * Per-section review entry for surfaces that already hold the section
     * (workbench timeline rows, inbox): the same reasons and guarded preview
     * URLs that reviewGroups() builds, or null when nothing flags the section.
     *
     * @return array{
     *     reasons: array<int, array{key: string, label: string, classes: string}>,
     *     review_reason: string|null,
     *     confirmable: bool,
     *     audio_url: string|null,
     *     video_url: string|null
     * }|null
     */
    public function reviewEntryFor(ServiceSection $section): ?array
    {
        $reasons = $this->reviewReasons($section);
        if ($reasons === []) {
            return null;
        }

        return [
            'reasons' => $reasons,
            'review_reason' => $this->reviewReasonLabel($section),
            'confirmable' => $this->confirmationSkipReason($section) === null,
            'audio_url' => $this->assetUrl($section, 'audio', $section->extracted_audio_path),
            'video_url' => $this->assetUrl($section, 'video', $section->extracted_video_path),
        ];
    }

    public function batchApprovalSkipReason(ServiceSection $section): ?string
    {
        $additionalReviewFlags = collect($this->reviewReasons($section))
            ->pluck('key')
            ->reject(static fn (string $key): bool => $key === 'pending_approval')
            ->values();

        if ($additionalReviewFlags->isNotEmpty()) {
            return 'blocked by other review flags';
        }

        return null;
    }

    public function confirmationSkipReason(ServiceSection $section): ?string
    {
        $reviewReasons = collect($this->reviewReasons($section));

        if ($reviewReasons->isEmpty() || $reviewReasons->every(
            static fn (array $reason): bool => $reason['key'] === 'pending_approval'
        )) {
            return 'not awaiting manual review';
        }

        if ($reviewReasons->contains('key', 'speaker_review')) {
            return 'speaker review required';
        }

        if ($reviewReasons->contains('key', 'unmatched_song')) {
            return 'unmatched song';
        }

        return null;
    }

    /**
     * @return EloquentCollection<int, ServiceSection>
     */
    private function reviewSections(): EloquentCollection
    {
        return $this->reviewSectionsQuery()->get();
    }

    /**
     * @return Builder<ServiceSection>
     */
    private function reviewSectionsQuery(): Builder
    {
        /**
         * Performance Optimization: Limits retrieved columns for sections to only those
         * required for dashboard and inbox rendering to reduce memory usage and DB I/O.
         */
        return $this->reviewCandidateSectionsBaseQuery()
            ->select([
                'id',
                'church_service_item_id',
                'media_processing_log_id',
                'published_sermon_id',
                'section_type',
                'title',
                'start_time',
                'end_time',
                'section_order',
                'confidence',
                'publication_status',
                'needs_manual_review',
                'metadata',
                'song_match_type',
                'extracted_audio_path',
                'extracted_video_path',
                'updated_at',
            ])
            ->with([
                'processingLog:id,processing_id,extracted_date,extracted_service,processing_metadata,church_service_id',
                'processingLog.churchService:id,date,service,needs_review',
                'churchServiceItem:id,church_service_id,title,song_id,type',
                'churchServiceItem.churchService:id,date,service,needs_review',
                'churchServiceItem.song:id,title',
            ])
            ->orderByDesc('updated_at');
    }

    /**
     * The base predicate for review-candidate sections (contract C1).
     *
     * @return Builder<ServiceSection>
     */
    private function reviewCandidateSectionsBaseQuery(): Builder
    {
        return ServiceSection::query()
            // Sections belonging to a superseded run (a duplicate/earlier
            // processing of the same service) are kept for audit but never
            // reviewed — the winning run owns the service's structure.
            ->notSuperseded()
            ->where(function (Builder $query): void {
                $query->where('needs_manual_review', true)
                    ->orWhere('publication_status', ServiceSectionPublicationStatus::PendingApproval->value)
                    ->orWhere(function (Builder $query): void {
                        $query->whereIn('section_type', array_map(
                            static fn (ServiceSectionType $type): string => $type->value,
                            array_filter(
                                ServiceSectionType::cases(),
                                static fn (ServiceSectionType $type): bool => $type->requiresStructuralUncertaintyReview(),
                            ),
                        ))->where('confidence', '<', ServiceSectionConfidence::HIGH_THRESHOLD)
                            // A confirmed song match is exempt: identity is settled,
                            // so structural confidence alone does not flag it (mirrors
                            // reviewReasons()).
                            ->where(function (Builder $query): void {
                                $query->whereNull('song_match_type')
                                    ->orWhere('song_match_type', '!=', ServiceSectionSongMatchType::Confirmed->value);
                            })
                            ->where(function (Builder $query): void {
                                $query->whereNull('metadata->manual_review->confirmed_at')
                                    ->orWhere('metadata->manual_review->confirmed_at', '');
                            })
                            // A suspected closing benediction is exempt from the
                            // low-confidence path (mirrors reviewReasons()). The
                            // whereNull guard keeps readings with no review_flags
                            // in review rather than excluding them on a null match.
                            ->where(function (Builder $query): void {
                                $query->whereNull('metadata->review_flags')
                                    ->orWhereJsonDoesntContain(
                                        'metadata->review_flags',
                                        ServiceStructureValidator::FLAG_BENEDICTION_SUSPECT,
                                    );
                            });
                    })
                    ->orWhereJsonContains('metadata->review_flags', 'heuristic_demotion')
                    ->orWhereJsonLength('metadata->song_publication_review->reasons', '>', 0)
                    ->orWhere(function (Builder $query): void {
                        $query->whereIn('song_match_type', [
                            ServiceSectionSongMatchType::Inferred->value,
                            ServiceSectionSongMatchType::Unmatched->value,
                        ])->where(function (Builder $query): void {
                            $query->whereNull('metadata->manual_review->song_match_reviewed_at')
                                ->orWhere('metadata->manual_review->song_match_reviewed_at', '');
                        });
                    })
                    ->when($this->hasActiveSpeakerProfiles(), function (Builder $query): void {
                        $query->orWhere(function (Builder $query): void {
                            $query->where('section_type', ServiceSectionType::ChildrensTalk->value)
                                ->whereNotNull('metadata->childrens_talk_speaker->predicted')
                                ->whereNull('metadata->childrens_talk_speaker->reviewed');
                        });
                    })
                    ->orWhere(function (Builder $query): void {
                        // Legacy/fallback song match checks
                        $query->where('section_type', ServiceSectionType::Song->value)
                            // A confirmed match is a settled identity, not an
                            // unmatched/unreviewed song, so it is exempt here too.
                            ->where(function (Builder $query): void {
                                $query->whereNull('song_match_type')
                                    ->orWhere('song_match_type', '!=', ServiceSectionSongMatchType::Confirmed->value);
                            })
                            ->where(function (Builder $query): void {
                                $query->whereNull('metadata->manual_review->song_match_reviewed_at')
                                    ->orWhere('metadata->manual_review->song_match_reviewed_at', '');
                            })
                            ->where(function (Builder $query): void {
                                $query->whereNull('church_service_item_id')
                                    ->orWhereHas('churchServiceItem', fn (Builder $query): Builder => $query->whereNull('song_id'));
                            });
                    });
            });
    }

    private function hasManualConfirmation(ServiceSection $section): bool
    {
        return filled(data_get($section->metadata?->toArray() ?? [], 'manual_review.confirmed_at'));
    }

    private function isSuspectedBenediction(ServiceSection $section): bool
    {
        $flags = $section->metadata['review_flags'] ?? [];

        return is_array($flags)
            && in_array(ServiceStructureValidator::FLAG_BENEDICTION_SUSPECT, $flags, true);
    }

    private function hasSongMatchReview(ServiceSection $section): bool
    {
        return filled(data_get($section->metadata?->toArray() ?? [], 'manual_review.song_match_reviewed_at'));
    }

    private function hasActiveSpeakerProfiles(): bool
    {
        return $this->hasActiveSpeakerProfiles ??= SpeakerProfile::query()
            ->configuredForSpeakerIdentification()
            ->exists();
    }

    /**
     * @param  EloquentCollection<int, ServiceSection>  $sections
     * @param  EloquentCollection<int, ChurchService>  $reviewServices
     * @return array<string, ChurchService>
     */
    private function serviceLookup(EloquentCollection $sections, EloquentCollection $reviewServices): array
    {
        $identities = $sections
            ->map(fn (ServiceSection $section): ?array => $this->identityResolver->resolve($section->processingLog))
            ->filter()
            ->unique(fn (array $identity): string => $this->serviceKey($identity['date'], $identity['service']))
            ->values();

        $dates = $identities->pluck('date')->unique()->values()->all();
        $services = $identities->map(fn (array $identity): string => $identity['service']->value)->unique()->values()->all();

        $matchedServices = ($dates === [] || $services === [])
            ? new EloquentCollection
            : ChurchService::query()
                ->whereIn('date', $dates)
                ->whereIn('service', $services)
                ->get();

        return $this->keyedServices($reviewServices->merge($matchedServices)->unique('id'));
    }

    /**
     * @param  Collection<int, ChurchService>  $services
     * @return array<string, ChurchService>
     */
    private function keyedServices(Collection $services): array
    {
        return $services
            ->keyBy(fn (ChurchService $service): string => $this->serviceKey($service->date->toDateString(), $service->service))
            ->all();
    }

    /**
     * @param  array<string, ChurchService>  $serviceLookup
     * @return array{
     *     key:string,
     *     date:Carbon|null,
     *     service:SermonService|null,
     *     service_model:ChurchService|null
     * }
     */
    private function resolveGroupContext(ServiceSection $section, array $serviceLookup, bool $lookupOnMiss = false): array
    {
        $service = $section->processingLog->churchService ?? $section->churchServiceItem?->churchService;
        if ($service instanceof ChurchService) {
            return [
                'key' => $this->serviceKey($service->date->toDateString(), $service->service),
                'date' => $service->date,
                'service' => $service->service,
                'service_model' => $service,
            ];
        }

        $identity = $this->identityResolver->resolve($section->processingLog);
        if ($identity !== null) {
            $key = $this->serviceKey($identity['date'], $identity['service']);

            $serviceModel = $serviceLookup[$key] ?? null;
            if ($serviceModel === null && $lookupOnMiss) {
                $serviceModel = $this->findServiceByIdentity($key, $identity['date'], $identity['service']);
            }

            return [
                'key' => $key,
                'date' => Carbon::parse($identity['date']),
                'service' => $identity['service'],
                'service_model' => $serviceModel,
            ];
        }

        return [
            'key' => 'processing-run-'.$section->media_processing_log_id,
            'date' => null,
            'service' => null,
            'service_model' => null,
        ];
    }

    /**
     * Memoised per-key service lookup for the lazily iterated (capped) path,
     * where the upfront bulk identity scan is skipped.
     */
    private function findServiceByIdentity(string $key, string $date, SermonService $service): ?ChurchService
    {
        if (! array_key_exists($key, $this->serviceKeyLookupCache)) {
            $this->serviceKeyLookupCache[$key] = ChurchService::query()
                ->whereDate('date', $date)
                ->where('service', $service->value)
                ->first();
        }

        return $this->serviceKeyLookupCache[$key];
    }

    /**
     * @return array{
     *     key:string,
     *     date:string|null,
     *     date_label:string,
     *     service_label:string,
     *     service_enum:SermonService|null,
     *     sort_date:string,
     *     service_sort:int,
     *     service:ChurchService|null,
     *     pending_approval_count:int,
     *     batch_ready_count:int,
     *     batch_blocked_count:int,
     *     sections:array<int, array{
     *         section:ServiceSection,
     *         reasons:array<int, array{key:string,label:string,classes:string}>,
     *         review_reason:string|null,
     *         audio_url:string|null,
     *         video_url:string|null,
     *         manual_edit_url:string|null
     *     }>
     * }
     */
    private function makeGroup(
        string $key,
        ?Carbon $date,
        ?SermonService $service,
        ?ChurchService $serviceModel
    ): array {
        $dateInstance = $date ?? $serviceModel?->date;
        $dateString = $dateInstance?->toDateString();

        return [
            'key' => $key,
            'date' => $dateString,
            'date_label' => $dateInstance?->format('j M Y') ?? 'Unknown date',
            'service_label' => $service?->label() ?? 'Unknown service',
            'service_enum' => $service,
            'sort_date' => $dateString ?? '',
            'service_sort' => $this->serviceSort($service),
            'service' => $serviceModel,
            'pending_approval_count' => 0,
            'batch_ready_count' => 0,
            'batch_blocked_count' => 0,
            'sections' => [],
        ];
    }

    private function reviewReasonLabel(ServiceSection $section): ?string
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $reason = $metadata['review_reason'] ?? null;

        if (! is_string($reason) || $reason === '') {
            return null;
        }

        return Str::headline($reason);
    }

    private function assetUrl(ServiceSection $section, string $asset, ?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (
            $section->publication_status === ServiceSectionPublicationStatus::Published
            || $section->published_sermon_id !== null
        ) {
            return null;
        }

        return match ($asset) {
            'audio' => route('admin.services.section-publications.preview-audio', $section),
            'video' => route('admin.services.section-publications.preview-video', $section),
            default => null,
        };
    }

    private function serviceKey(string $date, SermonService $service): string
    {
        return $date.'|'.$service->value;
    }

    private function serviceSort(?SermonService $service): int
    {
        return match ($service) {
            SermonService::Morning => 1,
            SermonService::Evening => 2,
            SermonService::Other => 3,
            default => 9,
        };
    }
}
