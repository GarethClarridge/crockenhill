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
        $sermonView = $this->presenter->presentForList($this->sermon);

        return view('components.sermon-card', [
            'sermonView' => $sermonView,
        ]);
    }
}
