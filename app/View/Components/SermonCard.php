<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class SermonCard extends Component
{
    public function __construct(
        private readonly SermonViewPresenter $presenter,
        public readonly Sermon $sermon,
    ) {}

    public function render(): View|Closure|string
    {
        $view = $this->presenter->presentForList($this->sermon);

        return view('components.sermon-card', [
            'sermonUrl' => $view['canonical_url'],
            'thumbnailUrl' => $view['plain_thumbnail_url'],
            'preacherName' => $view['preacher_name'],
            'reference' => $view['display_reference'],
            'preacherUrl' => $view['preacher_url'],
            'formattedDuration' => $view['formatted_duration'],
            'seriesUrl' => $view['series_url'],
            'dateString' => $view['date_string'],
            'serviceLabel' => $view['service_label'],
        ]);
    }
}
