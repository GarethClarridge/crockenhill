<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\Page;
use App\Models\Sermon;
use App\Services\SafeMarkdownRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LayoutPageComposer
{
    public function __construct(
        private readonly SafeMarkdownRenderer $markdownRenderer,
    ) {}

    public function compose(View $view): void
    {
        $page = $view->getData()['page'] ?? null;
        if ($page instanceof Page) {
            $this->composeUsingProvidedPage($view, $page);

            return;
        }

        $this->composeFromRequestSegments($view, request());
    }

    private function composeUsingProvidedPage(View $view, Page $page): void
    {
        $viewData = $view->getData();
        $content = array_key_exists('content', $viewData)
            ? (string) $viewData['content']
            : $this->markdownRenderer->convert($page->markdown);

        $area = $page->area->value;
        $slug = $page->slug;

        $links = $this->randomLinks(
            linkArea: $area,
            slugToExclude: $slug,
            secondSlugToExclude: $slug,
            excludeAdminPages: true,
            extraExcludedSlugs: ['privacy-policy'],
        );

        $view->with([
            'description' => '<meta name="description" content="'.$page->description.'">',
            'heading' => $page->heading,
            'content' => $content,
            'headingpicture' => $page->heading_image_url,
            'area' => $area,
            'slug' => $slug,
            'links' => $links,
        ]);
    }

    private function composeFromRequestSegments(View $view, Request $request): void
    {
        [$name, $slug, $area] = $this->resolveRouteSegments($request);

        $description = '';
        $heading = '';
        $headingpicture = '';
        $content = '';
        $links = new Collection;

        if ($request->segment(2) === 'sermons' && $request->segment(5) !== null) {
            $sermon = Sermon::query()
                ->where('slug', $request->segment(5))
                ->whereMonth('date', '=', (string) $request->segment(4))
                ->whereYear('date', '=', (string) $request->segment(3))
                ->first();

            if ($sermon) {
                $heading = $sermon->title;
                $headingpicture = '/images/headings/large/'.(string) $request->segment(5).'.webp';
                $links = $this->orderedLinks(
                    linkArea: (string) $slug,
                    slugToExclude: $slug,
                    secondSlugToExclude: $area,
                    extraExcludedSlugs: ['homepage'],
                );
                $description = '<meta name="description" content="'.$heading.'">';
            }
        } elseif (in_array((string) $request->segment(1), ['login', 'register', 'password'], true)) {
            $area = 'Members';
            $heading = ucwords(str_replace('-', ' ', (string) $slug));
            $headingpicture = '/images/headings/large/'.$area.'.webp';
            $links = $this->randomLinks(
                linkArea: $area,
                slugToExclude: $area,
                secondSlugToExclude: $slug,
                extraExcludedSlugs: ['homepage'],
            );
            $description = '<meta name="description" content="'.$heading.'">';
        } elseif ($request->segment(3) !== null) {
            $description = '<meta name="description" content="'.(string) $slug.': '.(string) $name.'">';

            $providedHeading = $view->getData()['heading'] ?? null;
            if (is_string($providedHeading) && $providedHeading !== '') {
                $heading = $providedHeading;
            } else {
                $lastSegment = $request->segment(count($request->segments())) ?? '';
                $heading = str_replace('-', ' ', ucwords($lastSegment));
            }

            $headingpicture = '/images/headings/large/'.(string) $slug.'.webp';

            if ($request->segment(2) === 'sermons') {
                $links = $this->orderedLinks(
                    linkArea: 'sermons',
                    slugToExclude: $slug,
                    secondSlugToExclude: $area,
                );
            } elseif ($request->segment(2) === 'members') {
                $links = $this->orderedLinks(
                    linkArea: 'members',
                    slugToExclude: $slug,
                    secondSlugToExclude: $area,
                    excludeAdminPages: true,
                );
            } else {
                $links = $this->orderedLinks(
                    linkArea: (string) $area,
                    slugToExclude: $slug,
                    secondSlugToExclude: $area,
                    excludeAdminPages: true,
                    extraExcludedSlugs: ['privacy-policy'],
                );
            }
        } elseif ($request->segment(2) !== null) {
            $page = Page::query()
                ->where('slug', $slug)
                ->where('area', $area)
                ->first();

            if ($page) {
                $description = '<meta name="description" content="'.$page->description.'">';
                $heading = $page->heading;
                $content = htmlspecialchars_decode($page->body);
                $headingpicture = $page->heading_image_url ?? '/images/headings/large/'.(string) $slug.'.webp';
            } else {
                $description = null;
                $heading = null;
                $headingpicture = '/images/headings/large/'.(string) $slug.'.webp';
            }

            if ($request->segment(2) === 'sermons') {
                $links = $this->orderedLinks(
                    linkArea: 'sermons',
                    slugToExclude: $slug,
                    secondSlugToExclude: $area,
                    excludeAdminPages: true,
                );
            } elseif ($request->segment(2) === 'members') {
                $links = $this->orderedLinks(
                    linkArea: 'members',
                    slugToExclude: $slug,
                    secondSlugToExclude: $area,
                    excludeAdminPages: true,
                );
            } elseif ($request->segment(1) === 'community') {
                $links = $this->orderedLinks(
                    linkArea: (string) $area,
                    slugToExclude: $slug,
                    secondSlugToExclude: $area,
                    excludeAdminPages: true,
                );
            } else {
                $links = $this->orderedLinks(
                    linkArea: (string) $area,
                    slugToExclude: $slug,
                    secondSlugToExclude: $area,
                    excludeAdminPages: true,
                    extraExcludedSlugs: ['privacy-policy', 'attending-in-person', 'resources'],
                );
            }
        } else {
            $page = Page::query()
                ->where('slug', $area)
                ->where('area', $area)
                ->first();

            if ($page) {
                $description = '<meta name="description" content="'.$page->description.'">';
                $heading = $page->heading;
                $content = htmlspecialchars_decode($page->body);
            } else {
                $description = '<meta name="description" content="Welcome to our Church.">';
                $heading = 'Welcome';
                $content = '';
            }

            $headingpicture = '/images/headings/large/'.(string) $area.'.webp';
            $links = $this->randomLinks(
                linkArea: (string) $area,
                slugToExclude: $area,
                secondSlugToExclude: $area,
                excludeAdminPages: true,
                extraExcludedSlugs: ['privacy-policy'],
            );
        }

        $view->with([
            'description' => $description,
            'heading' => $heading,
            'content' => $content,
            'headingpicture' => $headingpicture,
            'area' => $area,
            'slug' => $slug,
            'links' => $links,
        ]);
    }

    /**
     * @param  list<string>  $extraExcludedSlugs
     * @return Collection<int, Page>
     */
    private function orderedLinks(
        string $linkArea,
        ?string $slugToExclude,
        ?string $secondSlugToExclude,
        bool $excludeAdminPages = false,
        array $extraExcludedSlugs = [],
    ): Collection {
        if ($linkArea === '') {
            return new Collection;
        }

        return $this->linksQuery(
            linkArea: $linkArea,
            slugToExclude: $slugToExclude,
            secondSlugToExclude: $secondSlugToExclude,
            excludeAdminPages: $excludeAdminPages,
            extraExcludedSlugs: $extraExcludedSlugs,
        )->orderBy('slug', 'asc')->get();
    }

    /**
     * @param  list<string>  $extraExcludedSlugs
     * @return Collection<int, Page>
     */
    private function randomLinks(
        string $linkArea,
        ?string $slugToExclude,
        ?string $secondSlugToExclude,
        bool $excludeAdminPages = false,
        array $extraExcludedSlugs = [],
        int $limit = 5,
    ): Collection {
        if ($linkArea === '') {
            return new Collection;
        }

        return $this->linksQuery(
            linkArea: $linkArea,
            slugToExclude: $slugToExclude,
            secondSlugToExclude: $secondSlugToExclude,
            excludeAdminPages: $excludeAdminPages,
            extraExcludedSlugs: $extraExcludedSlugs,
        )->inRandomOrder()->take($limit)->get();
    }

    /**
     * @param  list<string>  $extraExcludedSlugs
     * @return Builder<Page>
     */
    private function linksQuery(
        string $linkArea,
        ?string $slugToExclude,
        ?string $secondSlugToExclude,
        bool $excludeAdminPages = false,
        array $extraExcludedSlugs = [],
    ): Builder {
        $query = Page::query()
            ->with('media')
            ->where('area', $linkArea);

        if ($slugToExclude !== null) {
            $query->where('slug', '!=', $slugToExclude);
        }

        if ($secondSlugToExclude !== null) {
            $query->where('slug', '!=', $secondSlugToExclude);
        }

        foreach ($extraExcludedSlugs as $extraExcludedSlug) {
            $query->where('slug', '!=', $extraExcludedSlug);
        }

        if ($excludeAdminPages) {
            $query->where('admin', '!=', 'yes');
        }

        return $query;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function resolveRouteSegments(Request $request): array
    {
        if ($request->segment(3) !== null) {
            return [
                $request->segment(3),
                $request->segment(2),
                $request->segment(1),
            ];
        }

        if ($request->segment(2) !== null) {
            return [
                null,
                $request->segment(2),
                $request->segment(1),
            ];
        }

        return [
            null,
            $request->segment(1),
            $request->segment(1),
        ];
    }
}
