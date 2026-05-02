<?php

declare(strict_types=1);

namespace App\Actions\InboundEmail;

use App\Enums\InboundEmailStatus;
use App\Models\InboundEmail;

class RejectInboundEmail
{
    /**
     * Reject an inbound email, recording the reviewer and timestamp in processing metadata.
     */
    public function execute(InboundEmail $inboundEmail, int $rejectedByUserId): void
    {
        $existingMetadata = is_array($inboundEmail->processing_metadata) ? $inboundEmail->processing_metadata : [];

        $inboundEmail->status = InboundEmailStatus::Rejected;
        $inboundEmail->processing_metadata = array_replace_recursive($existingMetadata, [
            'review' => [
                'rejected_at' => now()->toIso8601String(),
                'rejected_by_user_id' => $rejectedByUserId,
            ],
        ]);
        $inboundEmail->save();
    }
}
