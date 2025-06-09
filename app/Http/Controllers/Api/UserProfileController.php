<?php

namespace Crockenhill\Http\Controllers\Api;

use Crockenhill\Http\Controllers\Controller;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    public function __invoke(Request $request)
    {
        return $request->user();
    }
}
