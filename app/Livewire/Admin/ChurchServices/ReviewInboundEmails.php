<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Data\OosEmailParseResult;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\InboundEmail;
use App\Services\InboundEmailHtmlSanitizer;
use App\Services\InboundEmailImportService;
use App\Services\OosEmailParserService;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class ReviewInboundEmails extends Component
{
    use EscapesLikeWildcards;
    use WithAdminAuthorization;
    use WithNotifications;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    /** @var array<int, string> */
    protected array $queryString = ['search', 'statusFilter'];

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->abortIfDisabled();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function approve(
        int $inboundEmailId,
        OosEmailParserService $parser,
        InboundEmailImportService $importService,
    ): mixed {
        $this->authorizeAdmin();

        $inboundEmail = $this->findReviewableEmail($inboundEmailId);
        if (! $inboundEmail instanceof InboundEmail) {
            $this->error('Inbound email not found.');

            return null;
        }

        try {
            $parseResult = $importService->storedParseResult($inboundEmail);

            if (! $parseResult instanceof OosEmailParseResult) {
                $parseResult = $parser->parse($inboundEmail);
                $importService->storeParseResult($inboundEmail, $parseResult);
            }

            if (! $this->canApprove($parseResult)) {
                $this->error('This email still needs manual editing before it can be approved.');

                return null;
            }

            $reviewedByUserId = Auth::id();
            $churchService = $importService->import(
                $inboundEmail,
                $parseResult,
                reviewedByUserId: is_int($reviewedByUserId) ? $reviewedByUserId : null,
                reviewMode: 'direct_approve',
            );

            return $this->success(
                'Inbound email approved and imported.',
                redirectTo: route('admin.services.show', $churchService),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Unable to approve this inbound email right now.');

            return null;
        }
    }

    public function editAndApprove(int $inboundEmailId): mixed
    {
        $this->authorizeAdmin();

        $inboundEmail = $this->findReviewableEmail($inboundEmailId);
        if (! $inboundEmail instanceof InboundEmail) {
            $this->error('Inbound email not found.');

            return null;
        }

        return $this->redirect(
            route('admin.services.create', ['inboundEmailId' => $inboundEmail->id]),
            navigate: true,
        );
    }

    public function reject(int $inboundEmailId): void
    {
        $this->authorizeAdmin();

        $inboundEmail = $this->findReviewableEmail($inboundEmailId);
        if (! $inboundEmail instanceof InboundEmail) {
            $this->error('Inbound email not found.');

            return;
        }

        $inboundEmail->status = InboundEmailStatus::REJECTED;
        $inboundEmail->processing_metadata = $this->mergeProcessingMetadata(
            $inboundEmail->processing_metadata,
            [
                'review' => [
                    'rejected_at' => now()->toIso8601String(),
                    'rejected_by_user_id' => Auth::id(),
                ],
            ],
        );
        $inboundEmail->save();

        $this->success('Inbound email rejected.');
    }

    public function render(): View
    {
        $search = trim($this->search);
        $searchPattern = '%'.$this->escapeLike($search).'%';

        $inboundEmails = InboundEmail::query()
            ->whereIn('status', $this->reviewableStatuses())
            ->when($this->statusFilter !== '', fn (Builder $query): Builder => $query->where('status', $this->statusFilter))
            ->when($search !== '', function (Builder $query) use ($searchPattern): void {
                $query->where(function (Builder $searchQuery) use ($searchPattern): void {
                    $searchQuery->where('from', 'like', $searchPattern)
                        ->orWhere('subject', 'like', $searchPattern)
                        ->orWhere('message_id', 'like', $searchPattern);
                });
            })
            ->orderByDesc('received_at')
            ->paginate(15);

        $statusOptions = collect($this->reviewableStatuses())
            ->map(fn (string $status): array => [
                'id' => $status,
                'name' => InboundEmailStatus::from($status)->label(),
            ])
            ->all();

        /** @var InboundEmailHtmlSanitizer $htmlSanitizer */
        $htmlSanitizer = app(InboundEmailHtmlSanitizer::class);

        $reviewData = $inboundEmails->getCollection()
            ->mapWithKeys(fn (InboundEmail $inboundEmail): array => [
                $inboundEmail->id => $this->buildReviewData($inboundEmail, $htmlSanitizer),
            ])
            ->all();

        return view('livewire.admin.church-services.review-inbound-emails', [
            'inboundEmails' => $inboundEmails,
            'reviewData' => $reviewData,
            'statusOptions' => $statusOptions,
        ])->layout('layouts.admin', [
            'title' => 'Inbound Email Review',
            'heading' => 'Inbound Email Review',
        ]);
    }

    /**
     * @return list<string>
     */
    private function reviewableStatuses(): array
    {
        return [
            InboundEmailStatus::PENDING->value,
            InboundEmailStatus::FAILED->value,
        ];
    }

    private function findReviewableEmail(int $inboundEmailId): ?InboundEmail
    {
        return InboundEmail::query()
            ->whereKey($inboundEmailId)
            ->whereIn('status', $this->reviewableStatuses())
            ->first();
    }

    private function canApprove(OosEmailParseResult $parseResult): bool
    {
        return is_string($parseResult->date)
            && $parseResult->service instanceof SermonService
            && $parseResult->items !== [];
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }

    /**
     * @return array{
     *     preview_items: array<int, array<string, mixed>>,
     *     warnings: array<int, string>,
     *     raw_warnings_json: ?string,
     *     failure_message: ?string,
     *     resolved_date: mixed,
     *     resolved_service: mixed,
     *     confidence_score: ?float,
     *     plain_body: ?string,
     *     has_plain_body: bool,
     *     sanitized_html: ?string,
     *     has_html_body: bool,
     *     raw_parsing_json: ?string
     * }
     */
    private function buildReviewData(InboundEmail $inboundEmail, InboundEmailHtmlSanitizer $htmlSanitizer): array
    {
        $metadata = is_array($inboundEmail->processing_metadata) ? $inboundEmail->processing_metadata : [];
        $parsing = is_array($metadata['parsing'] ?? null) ? $metadata['parsing'] : [];
        $failure = is_array($metadata['failure'] ?? null) ? $metadata['failure'] : [];
        $previewItems = array_values(array_filter(
            is_array($parsing['items'] ?? null) ? $parsing['items'] : [],
            static fn (mixed $item): bool => is_array($item),
        ));
        $warnings = array_values(array_filter(
            is_array($parsing['warnings'] ?? null) ? $parsing['warnings'] : [],
            static fn (mixed $warning): bool => is_string($warning) && $warning !== '',
        ));
        $plainBody = $this->normalisePlainBody($inboundEmail->body_plain);
        $rawWarningsJson = $this->encodeJson($warnings !== [] ? $warnings : null);

        return [
            'preview_items' => $previewItems,
            'warnings' => $warnings,
            'raw_warnings_json' => $rawWarningsJson,
            'failure_message' => isset($failure['message']) && is_string($failure['message']) ? $failure['message'] : null,
            'resolved_date' => $parsing['resolved_date'] ?? null,
            'resolved_service' => $parsing['resolved_service'] ?? null,
            'confidence_score' => is_numeric($parsing['confidence_score'] ?? null) ? (float) $parsing['confidence_score'] : null,
            'plain_body' => $plainBody,
            'has_plain_body' => $plainBody !== null,
            'sanitized_html' => $htmlSanitizer->sanitize($inboundEmail->body_html),
            'has_html_body' => is_string($inboundEmail->body_html) && trim($inboundEmail->body_html) !== '',
            'raw_parsing_json' => $this->encodeJson($parsing !== [] ? $parsing : null),
        ];
    }

    private function normalisePlainBody(?string $plainBody): ?string
    {
        if (! is_string($plainBody) || trim($plainBody) === '') {
            return null;
        }

        return trim(str_replace(["\r\n", "\r"], "\n", $plainBody));
    }

    /**
     * @param  array<int, string>|array<string, mixed>|null  $data
     */
    private function encodeJson(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }

    /**
     * @param  array<string, mixed>|null  $existingMetadata
     * @param  array<string, mixed>  $newMetadata
     * @return array<string, mixed>
     */
    private function mergeProcessingMetadata(?array $existingMetadata, array $newMetadata): array
    {
        $existingMetadata = is_array($existingMetadata) ? $existingMetadata : [];

        return array_replace_recursive($existingMetadata, $newMetadata);
    }
}
