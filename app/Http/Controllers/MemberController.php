<?php

namespace App\Http\Controllers;

class MemberController extends Controller
{
    public function __invoke()
    {
        return view('members.home');
    }
}
