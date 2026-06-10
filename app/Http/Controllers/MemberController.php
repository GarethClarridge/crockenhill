<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Queries\AdminAttentionCounts;
use Illuminate\Contracts\View\View;

class MemberController extends Controller
{
    public function __invoke(AdminAttentionCounts $attentionCounts): View
    {
        $isAdmin = auth()->user()?->canAccessAdmin() === true;

        $counts = $isAdmin ? $attentionCounts->cached() : null;

        return view('members.home', [
            'heading' => 'Members',
            'pendingInboundEmailCount' => $counts['pending_emails'] ?? 0,
            'pendingSermonReviewCount' => $counts['awaiting_segment_runs'] ?? 0,
        ]);
    }
}
