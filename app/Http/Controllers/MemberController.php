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

        return view('members.home', [
            'heading' => 'Members',
            'reviewInboxCount' => $isAdmin ? $attentionCounts->total($attentionCounts->cached()) : 0,
            'serviceTrackingEnabled' => (bool) config('service-tracking.enabled', true),
        ]);
    }
}
