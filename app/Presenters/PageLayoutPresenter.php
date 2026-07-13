<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Page;
use App\Services\SafeMarkdownRenderer;

class PageLayoutPresenter
{
    public function __construct(
        private readonly SafeMarkdownRenderer $markdownRenderer,
    ) {}

    public function renderContent(Page $page): string
    {
        if (filled($page->markdown)) {
            return $this->markdownRenderer->convert($page->markdown);
        }

        return htmlspecialchars_decode($page->body);
    }
}
