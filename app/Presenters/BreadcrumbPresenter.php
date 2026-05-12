<?php

declare(strict_types=1);

namespace App\Presenters;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BreadcrumbPresenter
{
    public function __construct(private readonly Request $request) {}

    /**
     * Build the ordered breadcrumb items for the current request.
     *
     * @return list<array{name: string, item: string}>
     */
    public function items(string $area, string $heading): array
    {
        $items = [['name' => 'Home', 'item' => url('/')]];

        if ($this->request->segment(1) === 'admin') {
            $items = array_merge($items, $this->buildAdminItems());
        } elseif ($this->request->segment(1) !== null || $area !== '') {
            $items = array_merge($items, $this->buildPublicItems($area));
        }

        if (end($items)['item'] !== url()->current()) {
            $items[] = ['name' => $heading, 'item' => url()->current()];
        }

        return $items;
    }

    /**
     * Build the breadcrumb items for the admin area.
     *
     * @return list<array{name: string, item: string}>
     */
    private function buildAdminItems(): array
    {
        $items = [
            ['name' => 'Church', 'item' => url('church')],
            ['name' => 'Members', 'item' => url('church/members')],
        ];

        $section = (string) $this->request->segment(2);
        if ($section === '') {
            return $items;
        }

        $sectionName = $this->getAdminSectionName($section);

        if (count($this->request->segments()) >= 3) {
            $segment3 = $this->request->segment(3);

            if ($segment3 === 'songs') {
                $items[] = ['name' => 'Songs', 'item' => url('admin/services/songs')];
            } else {
                $items[] = ['name' => $sectionName, 'item' => url('admin/'.$section)];
            }
        }

        return $items;
    }

    /**
     * Resolve the display name for an admin section.
     */
    private function getAdminSectionName(string $section): string
    {
        return match ($section) {
            'pages' => 'Pages',
            'sermons' => 'Sermons',
            'meetings' => 'Meetings',
            'calendar-events' => 'Calendar Events',
            'sermon-upload' => 'Upload Sermon',
            default => Str::title(str_replace('-', ' ', $section)),
        };
    }

    /**
     * Build the breadcrumb items for the public area.
     *
     * @return list<array{name: string, item: string}>
     */
    private function buildPublicItems(string $area): array
    {
        $items = [];

        if ($area !== '') {
            $items[] = ['name' => Str::title($area), 'item' => url($area)];
        }

        $segment2 = $this->request->segment(2);
        if ($segment2 === null) {
            return $items;
        }

        if ($segment2 === 'sermons') {
            $items[] = ['name' => 'Sermons', 'item' => url('christ/sermons')];

            $segment3 = (string) $this->request->segment(3);
            if ($segment3 === 'preachers' && $this->request->segment(4) !== null) {
                $items[] = ['name' => 'Preachers', 'item' => url('christ/sermons/preachers')];
            } elseif ($segment3 === 'series' && $this->request->segment(4) !== null) {
                $items[] = ['name' => 'Series', 'item' => url('christ/sermons/series')];
            }
        } elseif ($segment2 === 'members') {
            $items[] = ['name' => 'Members', 'item' => url('church/members')];
        } elseif ($segment2 === 'childrens-corner') {
            $items[] = ['name' => "Children's Corner", 'item' => url('christ/childrens-corner')];
        } elseif ($segment2 === 'songs' && $this->request->segment(3) !== null) {
            $items[] = ['name' => 'Songs', 'item' => url('church/songs')];
        } elseif ($this->request->segment(1) === 'meetings' && $this->request->segment(3) === 'events') {
            $meetingSlug = (string) $segment2;
            $items[] = ['name' => Str::title(str_replace('-', ' ', $meetingSlug)), 'item' => url('community/'.$meetingSlug)];
        }

        return $items;
    }

    /**
     * Build the Schema.org BreadcrumbList JSON-LD array.
     *
     * @param  list<array{name: string, item: string}>  $items
     * @return array<string, mixed>
     */
    public function jsonLd(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(
                static fn (array $item, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'item' => $item['item'],
                ],
                $items,
                array_keys($items),
            ),
        ];
    }
}
