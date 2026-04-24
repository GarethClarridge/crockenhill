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
        return view('components.sermon-card', [
            'sermonUrl' => $this->presenter->canonicalUrl($this->sermon),
            'thumbnailUrl' => $this->presenter->plainThumbnailUrl($this->sermon),
            'preacherName' => $this->presenter->displayPreacherName($this->sermon),
            'reference' => $this->presenter->displayReference($this->sermon),
            'preacherUrl' => $this->presenter->preacherUrl($this->sermon),
            'formattedDuration' => $this->presenter->formattedDuration($this->sermon),
            'seriesUrl' => $this->presenter->seriesUrl($this->sermon),
        ]);
    }
}
