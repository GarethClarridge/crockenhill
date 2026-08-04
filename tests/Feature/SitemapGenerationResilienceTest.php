<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Services\Public\PublicChurchServiceArchiveService;
use App\Services\Public\SermonRepository;
use App\Services\Public\SitemapService;
use App\Services\Sermon\SermonExposurePolicy;
use App\Sitemap\SermonSitemapPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Sitemap\Sitemap;
use Tests\TestCase;

/**
 * Scheduled sitemap generation must not read stale flexible caches (O46) and
 * must replace the live file atomically (O47) — see docs/issues/README.md.
 */
class SitemapGenerationResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $path = app(SitemapService::class)->getFilePath();

        foreach (array_merge([$path], glob("{$path}.*") ?: []) as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function generation_includes_a_new_series_even_when_the_cached_series_list_is_warm(): void
    {
        Sermon::factory()->create(['series' => 'Old Series']);

        // Warm the flexible cache the browse pages use.
        $this->assertSame(['Old Series'], app(SermonRepository::class)->getSeriesForDisplay());

        Sermon::factory()->create(['series' => 'Brand New Series']);

        $this->artisan('sitemap:generate')->assertSuccessful();

        $sitemapXml = (string) file_get_contents(app(SitemapService::class)->getFilePath());
        $this->assertStringContainsString(
            route('sermons.series.show', ['series' => Str::slug('Brand New Series')]),
            $sitemapXml,
            'expected the freshly generated sitemap to include the new series archive URL despite the warm cache',
        );
    }

    #[Test]
    public function generation_includes_a_new_book_even_when_the_cached_book_list_is_warm(): void
    {
        Sermon::factory()->create(['reference' => 'Genesis 1:1-31']);

        // Warm the flexible cache the browse pages use.
        $this->assertContains('Genesis', app(SermonRepository::class)->getScriptureBooks()->all());

        Sermon::factory()->create(['reference' => 'Habakkuk 1:1-4']);

        $this->artisan('sitemap:generate')->assertSuccessful();

        $sitemapXml = (string) file_get_contents(app(SitemapService::class)->getFilePath());
        $this->assertStringContainsString(
            'book=Habakkuk',
            $sitemapXml,
            'expected the freshly generated sitemap to include the new book archive URL despite the warm cache',
        );
    }

    #[Test]
    public function a_failed_generation_leaves_the_previous_sitemap_untouched(): void
    {
        $service = new class(app(SermonExposurePolicy::class), app(SermonRepository::class), app(SermonSitemapPresenter::class), app(PublicChurchServiceArchiveService::class)) extends SitemapService
        {
            protected function writeSitemapFile(Sitemap $sitemap, string $temporaryPath): void
            {
                file_put_contents($temporaryPath, '<urlset><url>');
            }
        };

        $path = $service->getFilePath();
        $previousSitemap = '<?xml version="1.0"?><urlset><!-- last known good --></urlset>';
        file_put_contents($path, $previousSitemap);

        $failed = false;

        try {
            $service->generate();
        } catch (RuntimeException) {
            $failed = true;
        }

        $this->assertTrue($failed, 'expected generation to fail on truncated XML');
        $this->assertSame(
            $previousSitemap,
            file_get_contents($path),
            'expected the previous sitemap to survive a failed generation',
        );
        $this->assertSame([], glob("{$path}.*") ?: [], 'expected no temporary files to be left behind');
    }

    #[Test]
    public function a_successful_generation_replaces_the_file_and_cleans_up(): void
    {
        Sermon::factory()->create();

        $path = app(SitemapService::class)->getFilePath();
        file_put_contents($path, 'stale placeholder');

        $this->artisan('sitemap:generate')->assertSuccessful();

        $sitemapXml = (string) file_get_contents($path);
        $this->assertStringContainsString('<urlset', $sitemapXml);
        $this->assertStringNotContainsString('stale placeholder', $sitemapXml);
        $this->assertSame([], glob("{$path}.*") ?: [], 'expected no temporary files to be left behind');
    }
}
