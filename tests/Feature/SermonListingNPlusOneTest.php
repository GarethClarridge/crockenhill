<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use App\Services\Public\SermonRepository;
use App\Services\Public\SitemapService;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonListingNPlusOneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $sitemapService = app(SitemapService::class);
        $sitemapFile = $sitemapService->getFilePath();

        if (file_exists($sitemapFile)) {
            unlink($sitemapFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function repository_query_results_include_all_required_columns_for_meta_description(): void
    {
        // Create a sermon with short title/preacher/no series/no reference to
        // guarantee the 155-char meta description limit leaves room for the summary.
        $created = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'preacher' => 'Test Preacher',
            'series' => null,
            'reference' => null,
            'meta_description' => null,
            'summary' => 'Some unique summary for this test.',
            'show_summary' => true,
        ]);

        $repository = app(SermonRepository::class);
        $sermon = $repository->publicSermonQuery()->whereKey($created->id)->first();

        $this->assertNotNull($sermon);

        $this->assertStringContainsString('Some unique summary', app(SermonViewPresenter::class)->metaDescription($sermon));
    }

    #[Test]
    public function repository_query_resolves_media_through_the_rows_own_asset_disk(): void
    {
        config([
            'media-processing.storage.sermon_disk' => 'public',
            'thumbnail-generation.storage.disk' => 'public',
            'filesystems.disks.release_test' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/release_test'),
                'url' => 'https://release.test/media',
            ],
        ]);

        $created = Sermon::factory()->create([
            'asset_disk' => 'release_test',
            'thumbnail_file_path' => 'thumbnails/listing.jpg',
            'thumbnail_generated_at' => now(),
        ]);

        $sermon = app(SermonRepository::class)->publicSermonQuery()->whereKey($created->id)->first();

        $this->assertNotNull($sermon);
        $this->assertStringStartsWith(
            'https://release.test/media/thumbnails/listing.jpg',
            (string) app(SermonViewPresenter::class)->presentForList($sermon)['thumbnail_url'],
        );
    }

    #[Test]
    public function asset_disk_refuses_a_persisted_sermon_loaded_without_the_column(): void
    {
        $created = Sermon::factory()->create(['asset_disk' => 'release_test']);

        $sermon = Sermon::query()->select(['id', 'title'])->whereKey($created->id)->firstOrFail();

        $this->expectException(MissingAttributeException::class);

        $sermon->assetDisk('public');
    }

    #[Test]
    public function sitemap_service_query_results_include_all_required_columns_for_meta_description(): void
    {
        // Force the thumbnail generation to be mocked if needed, but we mostly care about the text content
        Sermon::factory()->create([
            'meta_description' => 'Sitemap unique meta description for this test.',
            'show_summary' => true,
            'thumbnail_file_path' => 'thumbnails/test.jpg',
            'thumbnail_generated_at' => now(),
            'video_file_path' => 'videos/test.mp4',
        ]);

        $sitemapService = app(SitemapService::class);
        $sitemapService->generate();

        $sitemapFile = $sitemapService->getFilePath();
        $this->assertFileExists($sitemapFile);
        $content = file_get_contents($sitemapFile);

        // Sitemap entries for sermons include <video:description> and <image:caption>
        // Both use metaDescription() — a stored meta_description takes priority over derived text
        $this->assertStringContainsString('Sitemap unique meta description for this test', $content);
    }
}
