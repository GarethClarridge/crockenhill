<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Sermons;

use App\Livewire\Sermons\BrowseSermons;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use App\Services\Scripture\SermonScriptureFilterIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrowseSermonsTest extends TestCase
{
    use RefreshDatabase;

    private SermonScriptureFilterIndexService $indexService;

    protected function setUp(): void
    {
        parent::setUp();

        SermonScriptureFilter::query()->delete();
        Sermon::query()->delete();
        Preacher::query()->delete();

        $this->indexService = app(SermonScriptureFilterIndexService::class);
    }

    #[Test]
    public function no_filters_show_flat_paginated_browse_results(): void
    {
        Sermon::factory()->create([
            'title' => 'April Sermon',
            'date' => '2026-04-13',
        ]);
        Sermon::factory()->create([
            'title' => 'March Sermon',
            'date' => '2026-03-30',
        ]);

        Livewire::test(BrowseSermons::class)
            ->assertSee('Filter sermons')
            ->assertSee('Showing')
            ->assertSee('2')
            ->assertSee('sermons')
            ->assertSee('April Sermon')
            ->assertSee('March Sermon')
            ->assertSee('13 April 2026')
            ->assertSee('30 March 2026')
            ->assertDontSee('Monday 13th April');
    }

    #[Test]
    public function results_count_updates_with_pagination(): void
    {
        Sermon::factory()->count(25)->create();

        Livewire::test(BrowseSermons::class)
            ->assertSee('Showing')
            ->assertSee('1')
            ->assertSee('24')
            ->assertSee('25')
            ->assertSee('sermons')
            ->call('gotoPage', 2)
            ->assertSee('Showing')
            ->assertSee('25')
            ->assertSee('of')
            ->assertSee('25')
            ->assertSee('sermons');
    }

    #[Test]
    public function pagination_change_dispatches_metadata_update_event(): void
    {
        Sermon::factory()->count(25)->create();

        Livewire::test(BrowseSermons::class)
            ->call('gotoPage', 2)
            ->assertDispatched('sermon-filters-updated');
    }

    #[Test]
    public function seo_title_includes_page_number_on_paginated_pages(): void
    {
        Sermon::factory()->count(25)->create();

        $component = Livewire::test(BrowseSermons::class)
            ->call('gotoPage', 2);

        $this->assertStringContainsString('Page 2', $component->get('seoTitle'));
    }

    #[Test]
    public function book_filter_returns_matching_sermons(): void
    {
        $john = $this->createIndexedSermon([
            'title' => 'John Sermon',
            'reference' => 'John 3:16',
        ]);
        $romans = $this->createIndexedSermon([
            'title' => 'Romans Sermon',
            'reference' => 'Romans 8:1-4',
        ]);

        Livewire::test(BrowseSermons::class)
            ->set('bookFilter', 'John')
            ->assertSee('John Sermon')
            ->assertDontSee('Romans Sermon');
    }

    #[Test]
    public function book_and_chapter_filter_returns_matching_sermons(): void
    {
        $this->createIndexedSermon([
            'title' => 'John 3 Sermon',
            'reference' => 'John 3:16',
        ]);
        $this->createIndexedSermon([
            'title' => 'John 4 Sermon',
            'reference' => 'John 4:1-8',
        ]);

        Livewire::test(BrowseSermons::class)
            ->set('bookFilter', 'John')
            ->set('chapterFilter', 3)
            ->assertSee('John 3 Sermon')
            ->assertDontSee('John 4 Sermon');
    }

    #[Test]
    public function preacher_and_series_filters_return_matching_sermons(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Peter Test', 'slug' => 'peter-test']);
        $this->createIndexedSermon([
            'title' => 'Matching Sermon',
            'reference' => 'Acts 2:1-4',
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'series' => 'Acts Series',
        ]);
        $this->createIndexedSermon([
            'title' => 'Different Series Sermon',
            'reference' => 'Acts 3:1-6',
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'series' => 'Different Series',
        ]);

        Livewire::test(BrowseSermons::class)
            ->set('preacherFilter', $preacher->id)
            ->set('seriesFilter', 'Acts Series')
            ->assertSee('Matching Sermon')
            ->assertDontSee('Different Series Sermon');
    }

    #[Test]
    public function active_filters_are_summarised_in_the_filter_bar(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Peter Test', 'slug' => 'peter-test-summary']);
        $this->createIndexedSermon([
            'title' => 'Summary Sermon',
            'reference' => 'Acts 2:1-4',
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'series' => 'Acts Series',
        ]);

        Livewire::test(BrowseSermons::class)
            ->set('bookFilter', 'Acts')
            ->set('chapterFilter', 2)
            ->set('preacherFilter', $preacher->id)
            ->set('seriesFilter', 'Acts Series')
            ->assertSee('Filtered by')
            ->assertSee('Acts 2')
            ->assertSee('Peter Test')
            ->assertSee('Acts Series');
    }

    #[Test]
    public function combined_filters_return_their_intersection(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Paul Test', 'slug' => 'paul-test']);

        $this->createIndexedSermon([
            'title' => 'Intersection Sermon',
            'reference' => 'Romans 8:1-4',
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'series' => 'Romans Series',
        ]);
        $this->createIndexedSermon([
            'title' => 'Wrong Chapter Sermon',
            'reference' => 'Romans 12:1-2',
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'series' => 'Romans Series',
        ]);
        $this->createIndexedSermon([
            'title' => 'Wrong Preacher Sermon',
            'reference' => 'Romans 8:5-8',
            'series' => 'Romans Series',
        ]);

        Livewire::test(BrowseSermons::class)
            ->set('bookFilter', 'Romans')
            ->set('chapterFilter', 8)
            ->set('preacherFilter', $preacher->id)
            ->set('seriesFilter', 'Romans Series')
            ->assertSee('Intersection Sermon')
            ->assertDontSee('Wrong Chapter Sermon')
            ->assertDontSee('Wrong Preacher Sermon');
    }

    #[Test]
    public function changing_book_resets_the_chapter_filter(): void
    {
        Livewire::test(BrowseSermons::class)
            ->set('bookFilter', 'John')
            ->set('chapterFilter', 3)
            ->set('bookFilter', 'Romans')
            ->assertSet('chapterFilter', null);
    }

    #[Test]
    public function clear_filters_resets_everything_and_returns_to_flat_archive_mode(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Clear Test', 'slug' => 'clear-test']);
        $this->createIndexedSermon([
            'title' => 'Archive Sermon',
            'reference' => 'John 3:16',
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'series' => 'Clear Series',
            'date' => '2026-04-13',
        ]);

        Livewire::test(BrowseSermons::class)
            ->set('bookFilter', 'John')
            ->set('chapterFilter', 3)
            ->set('preacherFilter', $preacher->id)
            ->set('seriesFilter', 'Clear Series')
            ->call('clearFilters')
            ->assertSet('bookFilter', null)
            ->assertSet('chapterFilter', null)
            ->assertSet('preacherFilter', null)
            ->assertSet('seriesFilter', null)
            ->assertSee('Archive Sermon')
            ->assertDontSee('Monday 13th April');
    }

    #[Test]
    public function individual_filters_can_be_removed(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Remove Test', 'slug' => 'remove-test']);

        Livewire::test(BrowseSermons::class)
            ->set('bookFilter', 'John')
            ->set('chapterFilter', 3)
            ->set('preacherFilter', $preacher->id)
            ->set('seriesFilter', 'Remove Series')
            ->call('removeFilter', 'book')
            ->assertSet('bookFilter', null)
            ->assertSet('chapterFilter', null)
            ->assertSet('preacherFilter', $preacher->id)
            ->assertSet('seriesFilter', 'Remove Series')
            ->call('removeFilter', 'preacher')
            ->assertSet('preacherFilter', null)
            ->assertSet('seriesFilter', 'Remove Series')
            ->call('removeFilter', 'series')
            ->assertSet('seriesFilter', null);
    }

    #[Test]
    public function url_state_round_trips_correctly(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Url Test', 'slug' => 'url-test']);
        $this->createIndexedSermon([
            'title' => 'URL Sermon',
            'reference' => 'John 3:16',
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'series' => 'URL Series',
        ]);

        Livewire::withQueryParams([
            'book' => 'John',
            'chapter' => '3',
            'preacher' => (string) $preacher->id,
            'series' => 'URL Series',
        ])->test(BrowseSermons::class)
            ->assertSet('bookFilter', 'John')
            ->assertSet('chapterFilter', 3)
            ->assertSet('preacherFilter', $preacher->id)
            ->assertSet('seriesFilter', 'URL Series')
            ->assertSee('URL Sermon');
    }

    #[Test]
    public function disabled_book_options_render(): void
    {
        $this->createIndexedSermon([
            'title' => 'John Only Sermon',
            'reference' => 'John 3:16',
        ]);

        Livewire::test(BrowseSermons::class)
            ->assertSee('option value="Genesis" disabled>Genesis</option>', false)
            ->assertSee('option value="John"', false)
            ->assertDontSee('option value="John" disabled', false);
    }

    #[Test]
    public function disabled_chapter_options_render(): void
    {
        $this->createIndexedSermon([
            'title' => 'John Only Sermon',
            'reference' => 'John 3:16',
        ]);

        Livewire::test(BrowseSermons::class)
            ->set('bookFilter', 'John')
            ->assertSee('option value="3"', false)
            ->assertDontSee('option value="3" disabled', false)
            ->assertSee('option value="4" disabled', false);
    }

    #[Test]
    public function empty_state_renders_when_no_sermons_match(): void
    {
        $this->createIndexedSermon([
            'title' => 'Romans Sermon',
            'reference' => 'Romans 8:1-4',
        ]);

        Livewire::test(BrowseSermons::class)
            ->set('bookFilter', 'John')
            ->assertSee('No sermons match these filters')
            ->assertSee('Clear filters')
            ->assertDontSee('Romans Sermon');
    }

    #[Test]
    public function rendering_browse_sermons_does_not_execute_duplicate_queries(): void
    {
        Sermon::factory()->count(5)->create();

        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        Livewire::test(BrowseSermons::class)->assertOk();

        // Let's count how many times each select query is executed.
        // We want to ensure no identical SELECT queries are executed multiple times.
        $duplicates = array_filter(array_count_values($queries), function ($count, $sql) {
            return $count > 1 && str_starts_with(strtolower($sql), 'select');
        }, ARRAY_FILTER_USE_BOTH);

        $this->assertEmpty(
            $duplicates,
            "Found duplicate select queries during BrowseSermons rendering:\n" . print_r($duplicates, true)
        );
    }

    private function createIndexedSermon(array $attributes): Sermon
    {
        $sermon = Sermon::factory()->create($attributes);

        $this->indexService->syncForSermon($sermon);

        return $sermon->fresh();
    }
}
