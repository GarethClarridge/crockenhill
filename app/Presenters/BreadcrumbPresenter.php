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
        $items = [];
        $items[] = ['name' => 'Home', 'item' => url('/')];

        if ($this->request->segment(1) === 'admin') {
            $items[] = ['name' => 'Church', 'item' => url('church')];
            $items[] = ['name' => 'Members', 'item' => url('church/members')];

            if (count($this->request->segments()) >= 2) {
                $section = $this->request->segment(2);
                $sectionName = match ($section) {
                    'pages' => 'Pages',
                    'sermons' => 'Sermons',
                    'meetings' => 'Meetings',
                    'calendar-events' => 'Calendar Events',
                    'sermon-upload' => 'Upload Sermon',
                    default => Str::title(str_replace('-', ' ', (string) $section)),
                };

                if (count($this->request->segments()) >= 3) {
                    $items[] = ['name' => $sectionName, 'item' => url('admin/'.$section)];
                }
            }
        } elseif (count($this->request->segments()) >= 2 || $area !== '') {
            $items[] = ['name' => Str::title($area), 'item' => url($area)];

            if (
                count($this->request->segments()) >= 3 ||
                (count($this->request->segments()) === 2 && $this->request->segment(2) !== null)
            ) {
                if ($this->request->segment(2) === 'sermons') {
                    $items[] = ['name' => 'Sermons', 'item' => url('christ/sermons')];

                    if (count($this->request->segments()) === 4) {
                        $seg3 = (string) $this->request->segment(3);
                        $items[] = ['name' => Str::title($seg3), 'item' => url('christ/sermons/'.$seg3)];
                    }
                } elseif ($this->request->segment(2) === 'members') {
                    $items[] = ['name' => 'Members', 'item' => url('church/members')];
                }
            }
        }

        if (end($items)['item'] !== url()->current()) {
            $items[] = ['name' => $heading, 'item' => url()->current()];
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
