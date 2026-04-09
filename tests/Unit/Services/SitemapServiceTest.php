<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Presenters\PageSitemapPresenter;
use App\Presenters\SermonSitemapPresenter;
use App\Repositories\SermonRepository;
use App\Services\SermonExposurePolicy;
use App\Services\SitemapService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SitemapServiceTest extends TestCase
{
    private SitemapService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $exposurePolicy = $this->createMock(SermonExposurePolicy::class);
        $sermonRepository = $this->createMock(SermonRepository::class);
        $pageSitemapPresenter = $this->createMock(PageSitemapPresenter::class);
        $sermonSitemapPresenter = $this->createMock(SermonSitemapPresenter::class);

        $this->service = new SitemapService(
            $exposurePolicy,
            $sermonRepository,
            $pageSitemapPresenter,
            $sermonSitemapPresenter
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
}
