<?php

declare(strict_types=1);

namespace App\View\Presenters;

use Illuminate\Http\Request;
use Illuminate\View\View;

interface PagePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(Request $request, View $view): array;
}
