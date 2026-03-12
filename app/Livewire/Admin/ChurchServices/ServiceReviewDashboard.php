<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Livewire\Admin\ChurchServices\Concerns\ManagesSectionPublication;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Models\Preacher;
use App\Models\ServiceSection;
use App\Services\ChildrensTalkSpeakerService;
use App\Services\MediaProcessingIdentityResolver;
use App\Support\ServiceSectionConfidence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class ServiceReviewDashboard extends Component
{
    use ManagesSectionPublication;
    use WithAdminAuthorization;
    use WithNotifications;

    /**
     * @var array<int, array{section_type:string,title:string}>
     */
    public array $sectionEdits = [];

    /**
     * @var array<int, array{preacher_id:string,speaker_name:string}>
     */
    public array $speakerEdits = [];

    private ?MediaProcessingIdentityResolver $identityResolver = null;

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->abortIfDisabled();
    }

    public function saveSection(int $sectionId): void
    {
        $this->authorizeAdmin();

        $section = ServiceSection::query()->find($sectionId);
        if (! $section instanceof ServiceSection) {
            $this->error('Section not found.');

            return;
        }

        $section->loadMissing('churchServiceItem');

        if (! $this->isReviewCandidate($section)) {
            $this->error('Section is no longer awaiting review.');

            return;
        }

        $payload = array_merge([
            'section_type' => $section->section_type->value,
            'title' => (string) ($section->title ?? ''),
            'preacher_id' => '',
            'speaker_name' => '',
        ], $this->sectionEdits[$sectionId] ?? [], $this->speakerEdits[$sectionId] ?? []);

        $validator = Validator::make(
            $payload,
            [
                'section_type' => ['required', Rule::in(array_map(
                    static fn (ServiceSectionType $type): string => $type->value,
                    ServiceSectionType::cases()
                ))],
                'title' => ['required', 'string', 'max:255'],
                'preacher_id' => ['nullable', 'integer', 'exists:preachers,id'],
                'speaker_name' => ['nullable', 'string', 'max:255'],
            ],
            [
                'section_type.required' => 'Choose a section type.',
                'title.required' => 'Enter a title before saving.',
            ]
        );

        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($payload, $section): void {
            $targetType = ServiceSectionType::tryFrom($payload['section_type']);

            if ($targetType !== ServiceSectionType::CHILDRENS_TALK) {
                return;
            }

            $existingSpeaker = $section->publicationChildrensTalkSpeaker();
            $speakerName = trim($payload['speaker_name']);
            $preacherId = $payload['preacher_id'];
            $hasSpeakerInput = (is_numeric($preacherId) && (int) $preacherId > 0) || $speakerName !== '';

            if ($existingSpeaker === null && ! $hasSpeakerInput) {
                $validator->errors()->add('speaker_name', "Choose a preacher or enter a fallback speaker name for this children's talk.");
            }
        });

        $validated = $validator->validate();
        $reviewedByUserId = is_numeric(Auth::id()) ? (int) Auth::id() : null;

        $section->section_type = ServiceSectionType::from($validated['section_type']);
        $section->title = trim($validated['title']);

        $metadata = is_array($section->metadata) ? $section->metadata : [];
        $speakerService = app(ChildrensTalkSpeakerService::class);

        if ($section->section_type === ServiceSectionType::CHILDRENS_TALK) {
            $speakerService->storeManualReview(
                $section,
                $this->normalizeSpeakerPreacherId($validated['preacher_id']),
                $validated['speaker_name'],
                $reviewedByUserId
            );
        } else {
            unset($metadata['childrens_talk_speaker']);
            $section->needs_manual_review = false;
        }

        $metadata = is_array($section->metadata) ? $section->metadata : $metadata;
        unset($metadata['review_reason'], $metadata['review_flags']);
        $metadata['manual_review'] = [
            'updated_at' => now()->toIso8601String(),
            'updated_by_user_id' => Auth::id(),
        ];
        $section->metadata = $metadata;

        if (
            ! $section->isPublishableType()
            && $section->publication_status !== ServiceSectionPublicationStatus::NOT_APPLICABLE
        ) {
            $section->transitionTo(ServiceSectionPublicationStatus::NOT_APPLICABLE);
        } elseif (
            $section->isPublishableType()
            && ! $section->needs_manual_review
            && $section->publication_status === ServiceSectionPublicationStatus::NOT_APPLICABLE
            && $this->hasExtractedMedia($section)
        ) {
            $section->transitionTo(ServiceSectionPublicationStatus::PENDING_APPROVAL);
        }

        $section->save();

        $this->sectionEdits[$section->id] = [
            'section_type' => $section->section_type->value,
            'title' => (string) ($section->title ?? ''),
        ];
        $publicationSpeaker = $section->publicationChildrensTalkSpeaker();
        $this->speakerEdits[$section->id] = [
            'preacher_id' => is_array($publicationSpeaker) && is_numeric($publicationSpeaker['preacher_id'] ?? null)
                ? (string) $publicationSpeaker['preacher_id']
                : '',
            'speaker_name' => $publicationSpeaker['preacher_name'] ?? '',
        ];

        $this->success('Section changes saved.');
    }

    public function markServiceReviewed(int $serviceId): void
    {
        $this->authorizeAdmin();

        $service = ChurchService::query()->find($serviceId);
        if (! $service instanceof ChurchService) {
            $this->error('Service not found.');

            return;
        }

        $importMetadata = is_array($service->import_metadata) ? $service->import_metadata : [];
        $importMetadata['manual_review'] = [
            'reviewed_at' => now()->toIso8601String(),
            'reviewed_by_user_id' => Auth::id(),
        ];
        unset($importMetadata['canonical_conflict']);

        $service->forceFill([
            'needs_review' => false,
            'import_metadata' => $importMetadata,
        ])->save();

        $this->success('Service marked as reviewed.');
    }

    public function approvePendingPublications(int $serviceId): void
    {
        $this->authorizeAdmin();

        $service = ChurchService::query()->find($serviceId);
        if (! $service instanceof ChurchService) {
            $this->error('Service not found.');

            return;
        }

        $pendingSections = $this->pendingPublicationSectionsForService($service);
        if ($pendingSections->isEmpty()) {
            $this->error('No pending publication approvals were found for this service.');

            return;
        }

        $auditMetadata = [
            'batch_id' => (string) Str::uuid(),
            'approved_at' => now()->toIso8601String(),
            'approved_by_user_id' => Auth::id(),
            'source' => 'service_review_dashboard',
            'action' => 'approve_pending_publications',
            'church_service_id' => $service->id,
        ];

        $approvedCount = 0;
        $skippedReasons = [];

        foreach ($pendingSections as $section) {
            $skipReason = $this->batchApprovalSkipReason($section);

            if ($skipReason !== null) {
                $skippedReasons[$skipReason] = ($skippedReasons[$skipReason] ?? 0) + 1;

                continue;
            }

            $error = $this->approveSectionForPublication($section, $auditMetadata);
            if ($error !== null) {
                $normalizedError = $this->normalizeBatchApprovalError($error);
                $skippedReasons[$normalizedError] = ($skippedReasons[$normalizedError] ?? 0) + 1;

                continue;
            }

            $approvedCount++;
        }

        if ($approvedCount === 0) {
            $this->error(sprintf(
                'No sections were batch-approved. %s',
                $this->formatBatchApprovalSkipSummary($skippedReasons)
            ));

            return;
        }

        if ($skippedReasons === []) {
            $this->success(sprintf(
                'Approved all %d pending %s for this service.',
                $approvedCount,
                Str::plural('publication', $approvedCount)
            ));

            return;
        }

        $this->success(sprintf(
            'Approved %d pending %s. %s',
            $approvedCount,
            Str::plural('publication', $approvedCount),
            $this->formatBatchApprovalSkipSummary($skippedReasons)
        ));
    }

    public function render(): View
    {
        $groups = $this->reviewGroups();
        $this->seedSectionEdits($groups);

        return view('livewire.admin.church-services.service-review-dashboard', [
            'groups' => $groups,
            'sectionTypeOptions' => $this->sectionTypeOptions(),
            'preacherOptions' => $this->preacherOptions(),
            'summary' => $this->summary($groups),
            'lowConfidenceThreshold' => ServiceSectionConfidence::HIGH_THRESHOLD,
        ])->layout('layouts.admin', [
            'title' => 'Service Review Dashboard',
            'heading' => 'Service Review Dashboard',
        ]);
    }

    /**
     * @return array<int, array{id:string,name:string}>
     */
    private function sectionTypeOptions(): array
    {
        return collect(ServiceSectionType::cases())
            ->map(fn (ServiceSectionType $type): array => [
                'id' => $type->value,
                'name' => $type->label(),
            ])
            ->all();
    }

    /**
     * @return array<int, array{id:string,name:string}>
     */
    private function preacherOptions(): array
    {
        return Preacher::active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Preacher $preacher): array => [
                'id' => (string) $preacher->id,
                'name' => $preacher->name,
            ])
            ->all();
    }

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
    private function reviewGroups(): array
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
                date: $service->date->toDateString(),
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
                'audio_url' => $this->assetUrl($section->extracted_audio_path),
                'video_url' => $this->assetUrl($section->extracted_video_path),
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
     *     sections:array<int, array{section:ServiceSection}>
     * }>  $groups
     */
    private function seedSectionEdits(array $groups): void
    {
        foreach ($groups as $group) {
            foreach ($group['sections'] as $entry) {
                $section = $entry['section'];

                if (! array_key_exists($section->id, $this->sectionEdits)) {
                    $this->sectionEdits[$section->id] = [
                        'section_type' => $section->section_type->value,
                        'title' => (string) ($section->title ?? ''),
                    ];
                }

                if (! array_key_exists($section->id, $this->speakerEdits)) {
                    $speaker = $section->publicationChildrensTalkSpeaker();
                    $this->speakerEdits[$section->id] = [
                        'preacher_id' => is_array($speaker) && is_numeric($speaker['preacher_id'] ?? null)
                            ? (string) $speaker['preacher_id']
                            : '',
                        'speaker_name' => is_string($speaker['preacher_name'] ?? null)
                            ? (string) $speaker['preacher_name']
                            : '',
                    ];
                }
            }
        }
    }

    /**
     * @param  array<int, array{
     *     service:ChurchService|null,
     *     sections:array<int, array{reasons:array<int, array{key:string}>}>
     * }>  $groups
     * @return array{service_groups:int,sections:int,services_needing_review:int,pending_approvals:int}
     */
    private function summary(array $groups): array
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

        return [
            'service_groups' => count($groups),
            'sections' => $sections,
            'services_needing_review' => $servicesNeedingReview,
            'pending_approvals' => $pendingApprovals,
        ];
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
            ->map(fn (ServiceSection $section): ?array => $this->identityResolver()->resolve($section->processingLog))
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
     *     date:string|null,
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
                'date' => $service->date->toDateString(),
                'service' => $service->service,
                'service_model' => $service,
            ];
        }

        $identity = $this->identityResolver()->resolve($section->processingLog);
        if ($identity !== null) {
            $key = $this->serviceKey($identity['date'], $identity['service']);

            return [
                'key' => $key,
                'date' => $identity['date'],
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
        ?string $date,
        ?SermonService $service,
        ?ChurchService $serviceModel
    ): array {
        return [
            'key' => $key,
            'date' => $date,
            'date_label' => $date !== null ? \Illuminate\Support\Carbon::parse($date)->format('j M Y') : 'Unknown date',
            'service_label' => $service?->label() ?? 'Unknown service',
            'service_enum' => $service,
            'sort_date' => $date ?? '',
            'service_sort' => $this->serviceSort($service),
            'service' => $serviceModel,
            'pending_approval_count' => 0,
            'batch_ready_count' => 0,
            'batch_blocked_count' => 0,
            'sections' => [],
        ];
    }

    /**
     * @return array<int, array{key:string,label:string,classes:string}>
     */
    private function reviewReasons(ServiceSection $section): array
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

        return $reasons;
    }

    private function reviewReasonLabel(ServiceSection $section): ?string
    {
        $metadata = is_array($section->metadata) ? $section->metadata : [];
        $reason = $metadata['review_reason'] ?? null;

        if (! is_string($reason) || $reason === '') {
            return null;
        }

        return Str::headline($reason);
    }

    private function isReviewCandidate(ServiceSection $section): bool
    {
        return $this->reviewReasons($section) !== [];
    }

    /**
     * @return EloquentCollection<int, ServiceSection>
     */
    private function pendingPublicationSectionsForService(ChurchService $service): EloquentCollection
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

    private function batchApprovalSkipReason(ServiceSection $section): ?string
    {
        $additionalReviewFlags = collect($this->reviewReasons($section))
            ->pluck('key')
            ->reject(static fn (string $key): bool => $key === 'pending_approval')
            ->values();

        if ($additionalReviewFlags->isNotEmpty()) {
            return 'blocked by other review flags';
        }

        $approvalError = $this->approvalBlocker($section);

        return $approvalError !== null
            ? $this->normalizeBatchApprovalError($approvalError)
            : null;
    }

    private function normalizeBatchApprovalError(string $message): string
    {
        return match ($message) {
            'Section media is missing. Reclassify and prepare candidates again.' => 'missing extracted media',
            "Choose a speaker for this children's talk before approving publication." => 'speaker review required',
            'This section cannot be approved in its current state.' => 'invalid approval state',
            default => Str::of($message)->trim()->lower()->toString(),
        };
    }

    /**
     * @param  array<string, int>  $skippedReasons
     */
    private function formatBatchApprovalSkipSummary(array $skippedReasons): string
    {
        $totalSkipped = array_sum($skippedReasons);
        if ($totalSkipped === 0) {
            return 'No sections were skipped.';
        }

        $reasons = collect($skippedReasons)
            ->map(fn (int $count, string $reason): string => sprintf('%s (%d)', $reason, $count))
            ->implode(', ');

        return sprintf(
            'Skipped %d %s: %s.',
            $totalSkipped,
            Str::plural('section', $totalSkipped),
            $reasons
        );
    }

    private function assetUrl(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return Storage::disk((string) config('media-processing.storage.sermon_disk', 'public'))->url($path);
    }

    private function serviceKey(string $date, SermonService $service): string
    {
        return $date.'|'.$service->value;
    }

    private function serviceSort(?SermonService $service): int
    {
        return match ($service) {
            SermonService::MORNING => 1,
            SermonService::EVENING => 2,
            SermonService::OTHER => 3,
            default => 9,
        };
    }

    private function identityResolver(): MediaProcessingIdentityResolver
    {
        return $this->identityResolver ??= app(MediaProcessingIdentityResolver::class);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }

    private function normalizeSpeakerPreacherId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
