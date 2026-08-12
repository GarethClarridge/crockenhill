<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices\Concerns;

use App\Actions\InboundEmail\ApproveInboundEmailImport;
use App\Actions\InboundEmail\RejectInboundEmail;
use App\Actions\InboundEmail\ReparseInboundEmail;
use App\Data\OosEmailImportResult;
use App\Enums\InboundEmailStatus;
use App\Enums\OosEmailContentScope;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait ManagesInboundEmailReview
{
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

        $createdService = $result->firstCreatedService();
        if ($createdService instanceof ChurchService) {
            return $this->success(
                $this->importOutcomeMessage($result),
                redirectTo: route('admin.services.show', $createdService),
            );
        }

        return $this->success($this->importOutcomeMessage($result));
    }

    public function editAndApproveEmail(int $inboundEmailId, ?string $planKey = null): mixed
    {
        $this->authorizeAdmin();

        $inboundEmail = $this->findReviewableEmail($inboundEmailId);
        if (! $inboundEmail instanceof InboundEmail) {
            $this->error('Inbound email not found.');

            return null;
        }

        $query = ['inboundEmailId' => $inboundEmail->id];
        if (is_string($planKey) && $planKey !== '') {
            $query['planKey'] = $planKey;
        }

        return $this->redirect(route('admin.services.create', $query), navigate: true);
    }

    public function retainEmailEvidence(int $inboundEmailId, ApproveInboundEmailImport $action): mixed
    {
        $this->authorizeAdmin();

        $inboundEmail = $this->findReviewableEmail($inboundEmailId);
        if (! $inboundEmail instanceof InboundEmail) {
            $this->error('Inbound email not found.');

            return null;
        }

        $userId = $this->adminUserId();
        if ($userId === null) {
            $this->error('Unable to retain this inbound email right now.');

            return null;
        }

        $result = $action->execute($inboundEmail, $userId, OosEmailContentScope::Partial);
        if (is_string($result)) {
            $this->error($result);

            return null;
        }

        return $this->success($this->importOutcomeMessage($result));
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

    private function importOutcomeMessage(OosEmailImportResult $result): string
    {
        $counts = [];

        foreach ($result->plans as $plan) {
            $label = $plan->outcome->value;
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        $parts = [];
        foreach ($counts as $label => $count) {
            $parts[] = "{$count} ".str_replace('_', ' ', $label);
        }

        return 'Inbound email processed: '.implode(', ', $parts).'.';
    }

    private function findReviewableEmail(int $inboundEmailId): ?InboundEmail
    {
        return InboundEmail::query()
            ->whereKey($inboundEmailId)
            ->whereIn('status', [InboundEmailStatus::Pending->value, InboundEmailStatus::Failed->value])
            ->first();
    }

    private function adminUserId(): ?int
    {
        $id = Auth::id();

        return is_int($id) ? $id : null;
    }
}
