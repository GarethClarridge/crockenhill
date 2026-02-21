<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Enums\SermonService;
use App\Models\Sermon;
use Illuminate\View\View;

class FooterComposer
{
    public function compose(View $view): void
    {
        $morning = Sermon::query()
            ->where('service', SermonService::MORNING->value)
            ->orderBy('date', 'desc')
            ->first();

        $evening = Sermon::query()
            ->where('service', SermonService::EVENING->value)
            ->orderBy('date', 'desc')
            ->first();

        $view->with([
            'morning' => $morning,
            'evening' => $evening,
        ]);
    }
}
