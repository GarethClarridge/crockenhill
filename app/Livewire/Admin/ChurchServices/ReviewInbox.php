<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Actions\InboundEmail\ApproveInboundEmailImport;
use App\Actions\InboundEmail\RejectInboundEmail;
use App\Actions\InboundEmail\ReparseInboundEmail;
use App\Actions\ServiceReview\MarkServiceReviewed;
use App\Enums\InboundEmailStatus;
use App\Livewire\Admin\ChurchServices\Concerns\ManagesSectionPublication;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Queries\AdminAttentionCounts;
use App\Queries\ReviewInboxQuery;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The "Monday-morning sweep" queue: one inbox for inbound emails, flagged
 * sections, paused sermon-segment runs, pending merges, and service review
 * flags, grouped by service with inline quick actions.
 */
class ReviewInbox extends Component
{
    use ManagesSectionPublication;
    use SanitizesLogData;
    use WithAdminAuthorization;
    use WithNotifications;

    #[Url(except: 'all')]
    public string $filter = 'all';

    public function mount(): void
    {
        $this->abortIfDisabled();
    }

    public function approveEmail(int $inboundEmailId, ApproveInboundEmailImport $action): mixed
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

    public function editAndApproveEmail(int $inboundEmailId): mixed
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

    public function reparseEmail(int $inboundEmailId, ReparseInboundEmail $action): void
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

    public function rejectEmail(int $inboundEmailId, RejectInboundEmail $action): void
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

        Log::warning('Inbound email rejected by admin', $this->sanitizeArrayForLog([
            'admin_id' => $userId,
            'inbound_email_id' => $inboundEmail->id,
            'message_id' => (string) $inboundEmail->message_id,
            'from' => (string) $inboundEmail->from,
            'subject' => (string) $inboundEmail->subject,
        ]));

        $action->execute($inboundEmail, $userId);

        $this->success('Inbound email rejected.');
    }

    public function markServiceReviewed(int $serviceId, MarkServiceReviewed $action): void
    {
        $this->authorizeAdmin();

        $service = ChurchService::query()->find($serviceId);
        if (! $service instanceof ChurchService) {
            $this->error('Service not found.');

            return;
        }

        $userId = $this->adminUserId();
        if ($userId === null) {
            $this->error('Unable to mark this service as reviewed right now.');

            return;
        }

        $action->execute($service, $userId);

        $this->success('Service marked as reviewed.');
    }

    public function render(ReviewInboxQuery $query, AdminAttentionCounts $attentionCounts): View
    {
        $inbox = $query->build();

        $visibleKinds = $this->visibleKinds();

        $groups = collect($inbox['groups'])
            ->map(function (array $group) use ($visibleKinds): array {
                $group['items'] = array_values(array_filter(
                    $group['items'],
                    fn (array $item): bool => in_array($item['kind'], $visibleKinds, true),
                ));

                return $group;
            })
            ->filter(fn (array $group): bool => $group['items'] !== [])
            ->values()
            ->all();

        return view('livewire.admin.church-services.review-inbox', [
            'groups' => $groups,
            'counts' => $inbox['counts'],
            'filterChips' => $this->filterChips($inbox['counts']),
            'overflowNotices' => $this->overflowNotices($inbox['counts'], $attentionCounts),
        ])->layout('layouts.admin', ['title' => 'Review inbox', 'heading' => 'Review inbox']);
    }

    /**
     * Sources whose true backlog exceeds the capped queue. True totals come
     * from AdminAttentionCounts (same predicates as the capped lists, briefly
     * cached — contract C1), so the notice can say "newest N of M" without
     * re-materialising the backlog.
     *
     * @param  array{all: int, emails: int, sections: int, segments: int, services: int}  $counts
     * @return list<array{label: string, shown: int, total: int}>
     */
    private function overflowNotices(array $counts, AdminAttentionCounts $attentionCounts): array
    {
        $totals = $attentionCounts->cached();

        $sources = [
            'emails' => ['label' => 'emails', 'total' => $totals['pending_emails']],
            'sections' => ['label' => 'sections', 'total' => $totals['flagged_sections']],
            'segments' => ['label' => 'segment confirmations', 'total' => $totals['awaiting_segment_runs']],
            'services' => ['label' => 'service items', 'total' => $totals['pending_merges'] + $totals['services_needing_review']],
        ];

        $notices = [];

        foreach ($sources as $key => $source) {
            if ($source['total'] > $counts[$key]) {
                $notices[] = ['label' => $source['label'], 'shown' => $counts[$key], 'total' => $source['total']];
            }
        }

        return $notices;
    }

    /**
     * @return list<string>
     */
    private function visibleKinds(): array
    {
        return match ($this->filter) {
            'emails' => ['email'],
            'sections' => ['section'],
            'segments' => ['segment'],
            'services' => ['merge', 'service_flag'],
            default => ['email', 'section', 'segment', 'merge', 'service_flag'],
        };
    }

    /**
     * Chips with a zero count are omitted (except All); the Sections chip is
     * also omitted when section publishing is disabled (contract C5 — its
     * count is already zero in that state).
     *
     * @param  array{all: int, emails: int, sections: int, segments: int, services: int}  $counts
     * @return list<array{key: string, label: string, count: int}>
     */
    private function filterChips(array $counts): array
    {
        $chips = [['key' => 'all', 'label' => 'All', 'count' => $counts['all']]];

        foreach (['emails' => 'Emails', 'sections' => 'Sections', 'segments' => 'Segments', 'services' => 'Services'] as $key => $label) {
            if ($counts[$key] > 0) {
                $chips[] = ['key' => $key, 'label' => $label, 'count' => $counts[$key]];
            }
        }

        return $chips;
    }

    private function findReviewableEmail(int $inboundEmailId): ?InboundEmail
    {
        return InboundEmail::query()
            ->whereKey($inboundEmailId)
            ->whereIn('status', [
                InboundEmailStatus::Pending->value,
                InboundEmailStatus::Failed->value,
            ])
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
