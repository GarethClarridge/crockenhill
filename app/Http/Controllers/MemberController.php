<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\InboundEmailStatus;
use App\Models\InboundEmail;
use Illuminate\Contracts\View\View;

class MemberController extends Controller
{
    public function __invoke(): View
    {
        $pendingInboundEmailCount = auth()->user()?->is_admin === true
            ? InboundEmail::query()
                ->whereIn('status', [
                    InboundEmailStatus::PENDING->value,
                    InboundEmailStatus::FAILED->value,
                ])
                ->count()
            : 0;

        return view('members.home', [
            'heading' => 'Members',
            'pendingInboundEmailCount' => $pendingInboundEmailCount,
        ]);
    }
}
