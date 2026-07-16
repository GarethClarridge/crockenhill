<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\RouteCanary;
use App\Enums\PageArea;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;

/**
 * Builds the canary set — one representative URL per public route type — used
 * by both the deploy-time manifest (`monitoring:canaries`) and the continuous
 * checker (the laravel-health RouteCanariesCheck), so the two never drift apart.
 *
 * Slugs for the cached detail routes are resolved from real records so the set
 * stays valid as content changes, and those routes request two hits: the second
 * read rehydrates the value through unserialize() under cache.serializable_classes
 * — the exact path that 500s when a cached class is renamed or missing from the
 * allow-list.
 */
class RouteCanaryRegistry
{
    /**
     * A string present in the shared site layout, so a 200 that rendered the
     * real chrome can be told apart from a soft error or stub response.
     */
    private const HTML_MARKER = 'Crockenhill';

    /**
     * @return list<RouteCanary>
     */
    public function all(): array
    {
        $canaries = [
            // Static + listing pages: assert the shared layout actually rendered.
            new RouteCanary('/', 200, 1, self::HTML_MARKER),
            new RouteCanary('/christ/sermons', 200, 1, self::HTML_MARKER),
            // XML sitemap exercises the complete public exposure query path.
            new RouteCanary('/sitemap.xml', 200, 1, '<urlset'),
            // The auth guard must redirect guests to login, not render or 500.
            new RouteCanary('/church/members', 302, 1, ''),
        ];

        if ($page = $this->representativePage()) {
            $canaries[] = new RouteCanary("/{$page->area->value}/{$page->slug}", 200, 2, self::HTML_MARKER);
        }

        if ($meeting = $this->representativeMeeting()) {
            $canaries[] = new RouteCanary("/community/{$meeting->slug}", 200, 2, self::HTML_MARKER);
        }

        if ($sermon = $this->representativeSermon()) {
            // The slug-only route is a legacy URL that 301-redirects to the
            // canonical date-based URL, so the render check targets the dated URL
            // and a separate canary guards that the redirect itself still works.
            $canaries[] = new RouteCanary("/christ/sermons/{$sermon->date->format('Y/m')}/{$sermon->slug}", 200, 2, self::HTML_MARKER);
            $canaries[] = new RouteCanary("/christ/sermons/{$sermon->slug}", 301, 1, '');

            $preacherSlug = $sermon->preacherProfile?->slug;

            if ($preacherSlug !== null && $preacherSlug !== '') {
                $canaries[] = new RouteCanary("/christ/sermons/preachers/{$preacherSlug}", 200, 1, self::HTML_MARKER);
            }
        }

        return $canaries;
    }

    /**
     * A guest-visible CMS page for the catch-all `/{area}/{slug}` route. Members
     * pages need auth, and `community` pages collide with the meeting route, so
     * both areas are excluded.
     */
    private function representativePage(): ?Page
    {
        return Page::query()
            ->public()
            ->whereNotIn('area', [PageArea::Members->value, PageArea::Community->value])
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->latest('updated_at')
            ->first();
    }

    private function representativeMeeting(): ?Meeting
    {
        return Meeting::query()
            ->publiclyAccessible()
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->latest('updated_at')
            ->first();
    }

    private function representativeSermon(): ?Sermon
    {
        return Sermon::query()
            ->whereVisibleInSitemap()
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->latest('date')
            ->first();
    }
}
