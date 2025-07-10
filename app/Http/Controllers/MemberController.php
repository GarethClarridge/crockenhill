<?php

declare(strict_types=1);

namespace App\Http\Controllers;

class MemberController extends Controller
{
    public function __invoke(): \Illuminate\Contracts\View\View
    {
        return view('members.home');
    }
}
