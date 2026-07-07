<?php

declare(strict_types=1);

namespace Tests\Integration\Presenters;

use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Seo\SermonArchiveSeoPresenter;
use App\Services\Public\PreacherListCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonArchiveSeoPresenterTest extends TestCase
{
    use RefreshDatabase;

    private SermonArchiveSeoPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = app(SermonArchiveSeoPresenter::class);
    }

    #[Test]
    public function image_returns_null_when_no_relevant_filters_active(): void
    {
        $filters = ['book' => 'Genesis', 'chapter' => null, 'preacherId' => null, 'series' => null];
        $this->assertNull($this->presenter->image($filters));
    }

    #[Test]
    public function image_returns_preacher_profile_image_when_preacher_filter_active(): void
    {
        $preacher = Preacher::factory()->create(['image_path' => 'preachers/spurgeon.jpg']);
        $filters = ['book' => null, 'chapter' => null, 'preacherId' => $preacher->id, 'series' => null];

        $this->assertSame($preacher->profile_image_url, $this->presenter->image($filters));
    }

    #[Test]
    public function image_returns_latest_sermon_thumbnail_when_series_filter_active(): void
    {
        Sermon::factory()->create([
            'series' => 'Life of David',
            'thumbnail_file_path' => 'sermons/david.jpg',
            'date' => now()->subDay(),
        ]);
        $filters = ['book' => null, 'chapter' => null, 'preacherId' => null, 'series' => 'Life of David'];

        $this->assertNotNull($this->presenter->image($filters));
        $this->assertStringContainsString('david.jpg', $this->presenter->image($filters));
    }

    #[Test]
    public function image_alt_returns_default_when_no_image_provided(): void
    {
        $filters = ['book' => null, 'chapter' => null, 'preacherId' => null, 'series' => null];
        $this->assertSame('Sermons at Crockenhill Baptist Church', $this->presenter->imageAlt($filters, null));
    }

    #[Test]
    public function image_alt_includes_preacher_name_when_preacher_filter_active(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Charles Spurgeon']);
        $filters = ['book' => null, 'chapter' => null, 'preacherId' => $preacher->id, 'series' => null];

        $this->assertSame('Preacher: Charles Spurgeon', $this->presenter->imageAlt($filters, 'some-image.jpg'));
    }

    #[Test]
    public function image_alt_includes_series_name_when_series_filter_active(): void
    {
        $filters = ['book' => null, 'chapter' => null, 'preacherId' => null, 'series' => 'Life of David'];

        $this->assertSame('Sermon Series: Life of David', $this->presenter->imageAlt($filters, 'some-image.jpg'));
    }

    #[Test]
    public function it_memoizes_preacher_image_lookups(): void
    {
        $preacher = Preacher::factory()->create(['image_path' => 'preachers/memo.jpg']);
        $filters = ['book' => null, 'chapter' => null, 'preacherId' => $preacher->id, 'series' => null];

        // First call
        $image1 = $this->presenter->image($filters);
        $this->assertSame($preacher->profile_image_url, $image1);

        // Update in DB
        $preacher->update(['image_path' => 'preachers/changed.jpg']);

        // Second call - should still return the memoized old image
        $image2 = $this->presenter->image($filters);
        $this->assertSame($image1, $image2);
    }

    #[Test]
    public function title_returns_default_when_no_filters_active(): void
    {
        $filters = [
            'book' => null,
            'chapter' => null,
            'preacherId' => null,
            'series' => null,
        ];

        $this->assertSame('Sermons', $this->presenter->title($filters));
    }

    #[Test]
    public function title_includes_book_filter(): void
    {
        $filters = [
            'book' => 'Genesis',
            'chapter' => null,
            'preacherId' => null,
            'series' => null,
        ];

        $this->assertSame('Genesis | Sermons', $this->presenter->title($filters));
    }

    #[Test]
    public function title_includes_book_and_chapter_filter(): void
    {
        $filters = [
            'book' => 'John',
            'chapter' => 3,
            'preacherId' => null,
            'series' => null,
        ];

        $this->assertSame('John 3 | Sermons', $this->presenter->title($filters));
    }

    #[Test]
    public function title_includes_preacher_filter(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Charles Spurgeon']);

        $filters = [
            'book' => null,
            'chapter' => null,
            'preacherId' => $preacher->id,
            'series' => null,
        ];

        $this->assertSame('Charles Spurgeon | Sermons', $this->presenter->title($filters));
    }

    #[Test]
    public function title_includes_series_filter(): void
    {
        $filters = [
            'book' => null,
            'chapter' => null,
            'preacherId' => null,
            'series' => 'Life of David',
        ];

        $this->assertSame('Life of David | Sermons', $this->presenter->title($filters));
    }

    #[Test]
    public function title_joins_multiple_filters(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Mark Drury']);

        $filters = [
            'book' => 'Romans',
            'chapter' => 8,
            'preacherId' => $preacher->id,
            'series' => 'The Gospel',
        ];

        $this->assertSame('Romans 8 | Mark Drury | The Gospel | Sermons', $this->presenter->title($filters));
    }

    #[Test]
    public function description_returns_default_when_no_filters_active(): void
    {
        $filters = [
            'book' => null,
            'chapter' => null,
            'preacherId' => null,
            'series' => null,
        ];

        $this->assertStringContainsString('Explore the sermon archive at Crockenhill Baptist Church', $this->presenter->description($filters));
    }

    #[Test]
    public function description_includes_book_and_chapter(): void
    {
        $filters = [
            'book' => 'Psalms',
            'chapter' => 23,
            'preacherId' => null,
            'series' => null,
        ];

        $description = $this->presenter->description($filters);
        $this->assertStringContainsString('on Psalms 23', $description);
    }

    #[Test]
    public function description_includes_preacher(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Alistair Begg']);

        $filters = [
            'book' => null,
            'chapter' => null,
            'preacherId' => $preacher->id,
            'series' => null,
        ];

        $description = $this->presenter->description($filters);
        $this->assertStringContainsString('by Alistair Begg', $description);
    }

    #[Test]
    public function description_includes_series(): void
    {
        $filters = [
            'book' => null,
            'chapter' => null,
            'preacherId' => null,
            'series' => 'Parables',
        ];

        $description = $this->presenter->description($filters);
        $this->assertStringContainsString("in the 'Parables' series", $description);
    }

    #[Test]
    public function description_joins_multiple_filters_correctly(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Peter Test']);

        $filters = [
            'book' => 'Acts',
            'chapter' => 2,
            'preacherId' => $preacher->id,
            'series' => 'Holy Spirit',
        ];

        $description = $this->presenter->description($filters);
        $this->assertStringContainsString('on Acts 2 by Peter Test in the \'Holy Spirit\' series', $description);
    }

    #[Test]
    public function canonical_returns_base_archive_url_when_no_filters(): void
    {
        $filters = [
            'book' => null,
            'chapter' => null,
            'preacherId' => null,
            'series' => null,
        ];

        $this->assertSame('http://localhost/christ/sermons', $this->presenter->canonical($filters));
    }

    #[Test]
    public function canonical_includes_filters_in_query_string(): void
    {
        $filters = [
            'book' => 'John',
            'chapter' => 3,
            'preacherId' => 123,
            'series' => 'Gospel',
        ];

        $url = $this->presenter->canonical($filters);
        $this->assertStringContainsString('book=John', $url);
        $this->assertStringContainsString('chapter=3', $url);
        $this->assertStringContainsString('preacher=123', $url);
        $this->assertStringContainsString('series=Gospel', $url);
    }

    #[Test]
    public function canonical_includes_page_number_when_greater_than_one(): void
    {
        $filters = ['book' => null, 'chapter' => null, 'preacherId' => null, 'series' => null];

        $this->assertSame('http://localhost/christ/sermons', $this->presenter->canonical($filters, 1));
        $this->assertStringContainsString('page=2', $this->presenter->canonical($filters, 2));
    }

    #[Test]
    public function it_resolves_preacher_name_from_active_list(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Active Preacher', 'is_active' => true]);

        // PreacherListCache is a scoped singleton, we need to ensure the cache is clear
        // or just let it load since we are in an integration test.
        app(PreacherListCache::class)->clearInternalCaches();

        $filters = [
            'book' => null,
            'chapter' => null,
            'preacherId' => $preacher->id,
            'series' => null,
        ];

        // Should use PreacherListCache::forPublicList() which includes active preachers
        $this->assertStringContainsString('Active Preacher', $this->presenter->title($filters));
    }

    #[Test]
    public function it_resolves_preacher_name_from_database_for_inactive_preachers(): void
    {
        $preacher = Preacher::factory()->inactive()->create(['name' => 'Inactive Preacher']);

        app(PreacherListCache::class)->clearInternalCaches();

        $filters = [
            'book' => null,
            'chapter' => null,
            'preacherId' => $preacher->id,
            'series' => null,
        ];

        // Inactive preacher won't be in forPublicList(), so it falls back to DB find()
        $this->assertStringContainsString('Inactive Preacher', $this->presenter->title($filters));
    }

    #[Test]
    public function preacher_archive_title_and_description_use_preacher_name(): void
    {
        $preacher = Preacher::factory()->make(['name' => 'John Smith']);

        $this->assertSame('Sermons by John Smith', $this->presenter->preacherTitle($preacher));
        $this->assertSame(
            'Browse all sermons preached by John Smith at Crockenhill Baptist Church.',
            $this->presenter->preacherDescription($preacher)
        );
    }

    #[Test]
    public function series_archive_title_and_description_use_series_name(): void
    {
        $this->assertSame("Sermon Series: The Lord's Prayer", $this->presenter->seriesTitle("The Lord's Prayer"));
        $this->assertSame(
            'Browse all sermons in the "The Lord\'s Prayer" series from Crockenhill Baptist Church.',
            $this->presenter->seriesDescription("The Lord's Prayer")
        );
    }

    #[Test]
    public function service_archive_title_and_description_use_sunday_labels(): void
    {
        $this->assertSame('Sunday Morning Services', $this->presenter->serviceTitle(SermonService::Morning, 'morning'));
        $this->assertSame('Sunday Evening Services', $this->presenter->serviceTitle(SermonService::Evening, 'evening'));
        $this->assertSame(
            'Listen to recent Sunday Morning sermons from Crockenhill Baptist Church.',
            $this->presenter->serviceDescription(SermonService::Morning, 'morning')
        );
    }

    #[Test]
    public function other_service_archive_label_titlecases_the_route_slug(): void
    {
        $this->assertSame('Other Services', $this->presenter->serviceTitle(SermonService::Other, 'other'));
        $this->assertSame(
            'Listen to recent Other sermons from Crockenhill Baptist Church.',
            $this->presenter->serviceDescription(SermonService::Other, 'other')
        );
    }
}
