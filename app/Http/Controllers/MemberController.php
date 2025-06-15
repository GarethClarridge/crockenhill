<?php

namespace App\Http\Controllers;

class MemberController extends Controller
{

  public function home()
  {
    return view('members.home');
  }
}
