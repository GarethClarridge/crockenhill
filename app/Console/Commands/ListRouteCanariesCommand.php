<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PageArea;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Console\Command;

/**
 * Emits a post-deploy canary manifest: one representative live URL per public
 * route type, so a deploy smoke check can hit each distinct rendering path and
 * fail the rollout before visitors see a regression.
 *
 * Output is one tab-separated record per line: `url`, expected HTTP status,
 * number of hits, and a body marker to grep for (empty marker = status only).
 * Slugs for the cached detail routes are resolved from real records so the
 * manifest stays valid as content changes, and those routes request two hits:
 * the second read rehydrates the value through unserialize() under
 * cache.serializable_classes — the exact path that 500s when a cached class is
 * renamed or missing from the allow-list.
 */
class ListRouteCanariesCommand extends Command
{
    protected $signature = 'monitoring:canaries';

    protected $description = 'Emit a post-deploy canary manifest (url, expected status, hits, body marker) covering each public route type';

    /**
     * A string present in the shared site layout, so a 200 that rendered the
     * real chrome can be told apart from a soft error or stub response.
     */
    private const HTML_MARKER = 'Crockenhill';

    public function handle(): int
    {
        foreach ($this->canaries() as [$url, $status, $hits, $marker]) {
            $this->line(implode("\t", [$url, (string) $status, (string) $hits, $marker]));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: string}>
     */
    private function canaries(): array
    {
        $canaries = [
            // Static + listing pages: assert the shared layout actually rendered.
            ['/', 200, 1, self::HTML_MARKER],
            ['/christ/sermons', 200, 1, self::HTML_MARKER],
            // XML sitemap iterates every public model via toSitemapTag().
            ['/sitemap.xml', 200, 1, '<urlset'],
            // The auth guard must redirect guests to login, not render or 500.
            ['/church/members', 302, 1, ''],
        ];

        if ($page = $this->representativePage()) {
            $canaries[] = ["/{$page->area->value}/{$page->slug}", 200, 2, self::HTML_MARKER];
        }

        if ($meeting = $this->representativeMeeting()) {
            $canaries[] = ["/community/{$meeting->slug}", 200, 2, self::HTML_MARKER];
        }

        if ($sermon = $this->representativeSermon()) {
            $canaries[] = ["/christ/sermons/{$sermon->slug}", 200, 2, self::HTML_MARKER];

            $preacherSlug = $sermon->preacherProfile?->slug;

            if ($preacherSlug !== null && $preacherSlug !== '') {
                $canaries[] = ["/christ/sermons/preachers/{$preacherSlug}", 200, 1, self::HTML_MARKER];
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
