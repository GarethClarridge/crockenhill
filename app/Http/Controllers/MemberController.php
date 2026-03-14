<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\InboundEmailStatus;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\InboundEmail;
use App\Models\MediaProcessingLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class MemberController extends Controller
{
    public function __invoke(): View
    {
        $isAdmin = auth()->user()?->is_admin === true;

        $pendingInboundEmailCount = $isAdmin
            ? InboundEmail::query()
                ->whereIn('status', [
                    InboundEmailStatus::PENDING->value,
                    InboundEmailStatus::FAILED->value,
                ])
                ->count()
            : 0;

        $pendingLivestreamReviewCount = $isAdmin
            ? MediaProcessingLog::query()
                ->where('processing_type', MediaType::Livestream->value)
                ->where('status', ProcessingStatus::FAILED->value)
                ->where('current_step', 'manual_review_required')
                ->whereNotNull(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(processing_metadata, '$.manual_review.reason_code'))"))
                ->count()
            : 0;

        return view('members.home', [
            'heading' => 'Members',
            'pendingInboundEmailCount' => $pendingInboundEmailCount,
            'pendingLivestreamReviewCount' => $pendingLivestreamReviewCount,
        ]);
    }
}
