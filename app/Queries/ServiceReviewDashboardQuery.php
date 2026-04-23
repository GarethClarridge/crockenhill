<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ServiceSection;
use App\Services\MediaProcessingIdentityResolver;
use App\Support\ServiceSectionConfidence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ServiceReviewDashboardQuery
{
    public function __construct(
        private readonly MediaProcessingIdentityResolver $identityResolver,
    ) {}

    /**
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
    public function reviewGroups(): array
    {
        $reviewServices = ChurchService::query()
            ->where('needs_review', true)
            ->orderByDesc('date')
            ->orderBy('service')
            ->get();

        $sections = $this->reviewSections();
        $serviceLookup = $this->serviceLookup($sections, $reviewServices);

        $groups = [];

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
            $reasons = $this->reviewReasons($section);
            if ($reasons === []) {
                continue;
            }

            $context = $this->resolveGroupContext($section, $serviceLookup);
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
                    ? route('admin.services.edit', $serviceModel)
                    : null,
            ];
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
                ->filter(fn (array $entry): bool => $entry['section']->publication_status === ServiceSectionPublicationStatus::PENDING_APPROVAL)
                ->count();
            $group['batch_ready_count'] = collect($group['sections'])
                ->filter(function (array $entry): bool {
                    $section = $entry['section'];

                    return $section->publication_status === ServiceSectionPublicationStatus::PENDING_APPROVAL
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

    public function pendingMergeCount(): int
    {
        return ChurchService::query()
            ->whereNotNull('pending_structure_merge_source')
            ->count();
    }

    /**
     * @return EloquentCollection<int, ServiceSection>
     */
    public function pendingPublicationSectionsForService(ChurchService $service): EloquentCollection
    {
        return ServiceSection::query()
            ->with([
                'processingLog:id,processing_id,extracted_date,extracted_service,processing_metadata,church_service_id',
                'processingLog.churchService:id,date,service,needs_review',
                'churchServiceItem:id,church_service_id,title,song_id,type',
                'churchServiceItem.churchService:id,date,service,needs_review',
            ])
            ->where('publication_status', ServiceSectionPublicationStatus::PENDING_APPROVAL->value)
            ->where(function (Builder $query) use ($service): void {
                $query->whereHas('processingLog', function (Builder $query) use ($service): void {
                    $query->where('church_service_id', $service->id)
                        ->orWhere(function (Builder $query) use ($service): void {
                            $query->whereDate('extracted_date', $service->date->toDateString())
                                ->where('extracted_service', $service->service->value);
                        });
                })->orWhereHas('churchServiceItem', fn (Builder $query): Builder => $query->where('church_service_id', $service->id));
            })
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();
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
            $section->section_type === ServiceSectionType::CHILDRENS_TALK
            && $section->publicationChildrensTalkSpeaker() === null
            && $section->predictedChildrensTalkSpeaker() !== null
        ) {
            $reasons[] = [
                'key' => 'speaker_review',
                'label' => 'Speaker review',
                'classes' => 'bg-cbc-teal-light/15 text-cbc-teal-dark',
            ];
        }

        if ($section->publication_status === ServiceSectionPublicationStatus::PENDING_APPROVAL) {
            $reasons[] = [
                'key' => 'pending_approval',
                'label' => 'Pending approval',
                'classes' => 'bg-amber-100 text-amber-800',
            ];
        }

        if ($section->confidence !== null && $section->confidence < ServiceSectionConfidence::HIGH_THRESHOLD) {
            $reasons[] = [
                'key' => 'low_confidence',
                'label' => 'Low confidence',
                'classes' => 'bg-slate-200 text-slate-800',
            ];
        }

        if ($section->hasInferredSongMatch()) {
            $reasons[] = [
                'key' => 'inferred_song_label',
                'label' => 'Inferred song label',
                'classes' => 'bg-violet-100 text-violet-800',
            ];
        }

        if ($section->hasUnmatchedSongMatch()) {
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

    public function isReviewCandidate(ServiceSection $section): bool
    {
        return $this->reviewReasons($section) !== [];
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

    /**
     * @return EloquentCollection<int, ServiceSection>
     */
    private function reviewSections(): EloquentCollection
    {
        return ServiceSection::query()
            ->with([
                'processingLog:id,processing_id,extracted_date,extracted_service,processing_metadata,church_service_id',
                'processingLog.churchService:id,date,service,needs_review',
                'churchServiceItem:id,church_service_id,title,song_id,type',
                'churchServiceItem.churchService:id,date,service,needs_review',
                'churchServiceItem.song:id,title',
            ])
            ->where(function (Builder $query): void {
                $query->where('needs_manual_review', true)
                    ->orWhere('publication_status', ServiceSectionPublicationStatus::PENDING_APPROVAL->value)
                    ->orWhere('confidence', '<', ServiceSectionConfidence::HIGH_THRESHOLD)
                    ->orWhere(function (Builder $query): void {
                        $query->where('section_type', ServiceSectionType::SONG->value)
                            ->where(function (Builder $query): void {
                                $query->whereNull('church_service_item_id')
                                    ->orWhereHas('churchServiceItem', fn (Builder $query): Builder => $query->whereNull('song_id'));
                            });
                    });
            })
            ->orderByDesc('updated_at')
            ->get();
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

        return $reviewServices
            ->merge($matchedServices)
            ->unique('id')
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
    private function resolveGroupContext(ServiceSection $section, array $serviceLookup): array
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

            return [
                'key' => $key,
                'date' => Carbon::parse($identity['date']),
                'service' => $identity['service'],
                'service_model' => $serviceLookup[$key] ?? null,
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
            $section->publication_status === ServiceSectionPublicationStatus::PUBLISHED
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
