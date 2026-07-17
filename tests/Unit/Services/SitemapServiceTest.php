<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\Public\SermonRepository;
use App\Services\Public\SitemapService;
use App\Services\Sermon\SermonExposurePolicy;
use App\Sitemap\SermonSitemapPresenter;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SitemapServiceTest extends TestCase
{
    private SitemapService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Meeting::query()->delete();
        Sermon::query()->delete();
        Preacher::query()->delete();
        Page::query()->delete();

        $exposurePolicy = $this->createStub(SermonExposurePolicy::class);
        $sermonRepository = $this->createStub(SermonRepository::class);
        $sermonSitemapPresenter = $this->createStub(SermonSitemapPresenter::class);

        $this->service = new SitemapService(
            $exposurePolicy,
            $sermonRepository,
            $sermonSitemapPresenter,
        );
    }

    #[Test]
    public function get_file_path_returns_default_path(): void
    {
        // Ensure environment is not 'testing' or test_token is null to get default
        Config::set('app.test_token', null);

        $this->assertEquals(public_path('sitemap.xml'), $this->service->getFilePath());
    }

    #[Test]
    public function get_file_path_includes_test_token_in_testing_environment(): void
    {
        Config::set('app.test_token', '12345');

        // Note: We expect 'testing' environment as standard for tests.
        // We're testing the logic that combines environment check and token presence.
        $this->assertTrue(app()->environment('testing'), 'Test must run in testing environment');
        $this->assertEquals(public_path('sitemap-test-12345.xml'), $this->service->getFilePath());
    }

    #[Test]
    public function should_regenerate_returns_true_if_file_missing(): void
    {
        $filePath = public_path('non-existent-sitemap.xml');

        // Mock getFilePath to return our non-existent path
        $service = $this->getMockBuilder(SitemapService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFilePath'])
            ->getMock();

        $service->method('getFilePath')->willReturn($filePath);

        $this->assertTrue($service->shouldRegenerate());
    }

    #[Test]
    public function should_regenerate_returns_false_if_file_exists(): void
    {
        $filePath = tempnam(sys_get_temp_dir(), 'sitemap');

        try {
            // Mock getFilePath to return our temporary file
            $service = $this->getMockBuilder(SitemapService::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getFilePath'])
                ->getMock();

            $service->method('getFilePath')->willReturn($filePath);

            $this->assertFalse($service->shouldRegenerate());
        } finally {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    #[Test]
    public function generate_includes_bible_book_archive_urls_in_sitemap(): void
    {
        $filePath = tempnam(sys_get_temp_dir(), 'sitemap').'.xml';

        $exposurePolicy = $this->createStub(SermonExposurePolicy::class);
        $exposurePolicy->method('childrensTalksArePublic')->willReturn(false);

        $sermonRepository = $this->createStub(SermonRepository::class);
        $sermonRepository->method('getSermonsByService')->willReturn(collect());
        $sermonRepository->method('getExistingSeries')->willReturn([]);
        $sermonRepository->method('getSeriesForDisplay')->willReturn([]);
        $sermonRepository->method('getExistingScriptureBooks')->willReturn(collect(['Genesis', 'John']));

        $service = $this->getMockBuilder(SitemapService::class)
            ->setConstructorArgs([
                $exposurePolicy,
                $sermonRepository,
                $this->createStub(SermonSitemapPresenter::class),
            ])
            ->onlyMethods(['getFilePath'])
            ->getMock();

        $service->method('getFilePath')->willReturn($filePath);

        try {
            $service->generate();

            $this->assertFileExists($filePath);
            $xml = file_get_contents($filePath);
            $this->assertStringContainsString('book=Genesis', $xml);
            $this->assertStringContainsString('book=John', $xml);
        } finally {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }
}
