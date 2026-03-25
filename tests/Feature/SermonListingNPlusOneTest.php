<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Repositories\SermonRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SermonListingNPlusOneTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @test
     */
    public function repository_query_results_include_all_required_columns_for_meta_description(): void
    {
        // Create 1 sermon with a summary and show_summary = true
        Sermon::factory()->create([
            'meta_description' => null,
            'summary' => 'Some unique summary for this test.',
            'show_summary' => true,
        ]);

        $repository = new SermonRepository;
        $sermon = $repository->publicSermonQuery()->first();

        $this->assertNotNull($sermon);

        $this->assertStringContainsString('Some unique summary', $sermon->meta_description);
    }

    /**
     * @test
     */
    public function sitemap_service_query_results_include_all_required_columns_for_meta_description(): void
    {
        // Force the thumbnail generation to be mocked if needed, but we mostly care about the text content
        Sermon::factory()->create([
            'meta_description' => null,
            'summary' => 'Sitemap unique summary for this test.',
            'show_summary' => true,
            'thumbnail_file_path' => 'thumbnails/test.jpg',
            'thumbnail_generated_at' => now(),
            'video_file_path' => 'videos/test.mp4',
        ]);

        $sitemapService = app(\App\Services\SitemapService::class);
        $sitemapService->generate();

        $sitemapFile = $sitemapService->getFilePath();
        $this->assertFileExists($sitemapFile);
        $content = file_get_contents($sitemapFile);

        // Sitemap entries for sermons include <video:description> and <image:caption>
        // Both use meta_description or summary
        $this->assertStringContainsString('Sitemap unique summary', $content);

        if (file_exists($sitemapFile)) {
            unlink($sitemapFile);
        }
    }
}
