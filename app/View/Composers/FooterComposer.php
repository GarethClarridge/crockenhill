<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\Sermon;
use Illuminate\View\View;

class FooterComposer
{
    public function compose(View $view): void
    {
        // Performance: Removed unused $morning and $evening sermon queries
    }
}
