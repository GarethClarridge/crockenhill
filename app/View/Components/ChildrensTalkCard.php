<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class ChildrensTalkCard extends Component
{
    public function __construct(
        private readonly SermonViewPresenter $presenter,
        public readonly Sermon $sermon,
    ) {}

    public function render(): View|Closure|string
    {
        return view('components.childrens-talk-card', [
            'cardThumbnailUrl' => $this->presenter->cardThumbnailUrl($this->sermon),
            'speakerName' => $this->presenter->displayPreacherName($this->sermon),
            'hasAudio' => filled($this->sermon->audio_file_path),
            'hasVideo' => filled($this->presenter->videoUrl($this->sermon)),
        ]);
    }
}
