<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Actions\InboundEmail\ApproveInboundEmailImport;
use App\Actions\InboundEmail\InboundEmailPreviewFactory;
use App\Actions\InboundEmail\RejectInboundEmail;
use App\Actions\InboundEmail\ReparseInboundEmail;
use App\Enums\InboundEmailStatus;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\InboundEmail;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ReviewInboundEmails extends Component
{
    use EscapesLikeWildcards;
    use WithAdminAuthorization;
    use WithNotifications;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $statusFilter = '';

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

    public function approve(int $inboundEmailId, ApproveInboundEmailImport $action): mixed
    {

        $this->authorizeAdmin();

        $inboundEmail = $this->findReviewableEmail($inboundEmailId);
        if (! $inboundEmail instanceof InboundEmail) {
            $this->error('Inbound email not found.');

            return null;
        }

        $userId = $this->adminUserId();
        if ($userId === null) {
            $this->error('Unable to approve this inbound email right now.');

            return null;
        }

        $result = $action->execute($inboundEmail, $userId);

        if (is_string($result)) {
            $this->error($result);

            return null;
        }

        return $this->success(
            'Inbound email approved and imported.',
            redirectTo: route('admin.services.show', $result),
        );
    }

    /**
     * Redirect to the manual edit form pre-filled with this email's parse data.
     *
     * Intentionally kept inline: this is a pure navigation handoff with no state
     * mutation or write orchestration, so extraction to a separate action adds no value.
     */
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

    public function reparse(int $inboundEmailId, ReparseInboundEmail $action): void
    {

        $this->authorizeAdmin();

        $inboundEmail = $this->findReviewableEmail($inboundEmailId);
        if (! $inboundEmail instanceof InboundEmail) {
            $this->error('Inbound email not found.');

            return;
        }

        $error = $action->execute($inboundEmail);

        if ($error !== null) {
            $this->error($error);

            return;
        }

        $this->success('Inbound email re-parsed. Review the updated preview before approving.');
    }

    public function reject(int $inboundEmailId, RejectInboundEmail $action): void
    {

        $this->authorizeAdmin();

        $inboundEmail = $this->findReviewableEmail($inboundEmailId);
        if (! $inboundEmail instanceof InboundEmail) {
            $this->error('Inbound email not found.');

            return;
        }

        $userId = $this->adminUserId();
        if ($userId === null) {
            $this->error('Unable to reject this inbound email right now.');

            return;
        }

        $action->execute($inboundEmail, $userId);

        $this->success('Inbound email rejected.');
    }

    public function render(InboundEmailPreviewFactory $previewFactory): View
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

        $reviewData = $inboundEmails->getCollection()
            ->mapWithKeys(fn (InboundEmail $inboundEmail): array => [
                $inboundEmail->id => $previewFactory->build($inboundEmail),
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

    private function adminUserId(): ?int
    {
        $id = Auth::id();

        return is_int($id) ? $id : null;
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
