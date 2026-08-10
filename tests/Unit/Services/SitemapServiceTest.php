<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\Public\PublicChurchServiceArchiveService;
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
        ChurchServiceItem::query()->forceDelete();
        ChurchService::query()->delete();

        $exposurePolicy = $this->createStub(SermonExposurePolicy::class);
        $sermonRepository = $this->createStub(SermonRepository::class);
        $sermonSitemapPresenter = $this->createStub(SermonSitemapPresenter::class);

        $this->service = new SitemapService(
            $exposurePolicy,
            $sermonRepository,
            $sermonSitemapPresenter,
            app(PublicChurchServiceArchiveService::class),
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
                app(PublicChurchServiceArchiveService::class),
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

    #[Test]
    public function generate_indexes_only_church_services_with_public_content(): void
    {
        config(['church.services.public_from' => '2000-01-01']);

        $listed = ChurchService::factory()->create([
            'date' => '2026-06-14',
            'service' => SermonService::Morning,
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $listed->id,
            'position' => 1,
            'title' => 'Romans 8:28-39',
            'type' => 'bibles',
        ]);

        $withheld = ChurchService::factory()->create([
            'date' => '2026-06-14',
            'service' => SermonService::Evening,
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $withheld->id,
            'position' => 1,
            'title' => 'ChurchNotices.odp',
            'type' => 'presentations',
        ]);

        $filePath = tempnam(sys_get_temp_dir(), 'sitemap').'.xml';

        $service = $this->getMockBuilder(SitemapService::class)
            ->setConstructorArgs([
                $this->createStub(SermonExposurePolicy::class),
                $this->stubbedSermonRepository(),
                $this->createStub(SermonSitemapPresenter::class),
                app(PublicChurchServiceArchiveService::class),
            ])
            ->onlyMethods(['getFilePath'])
            ->getMock();

        $service->method('getFilePath')->willReturn($filePath);

        try {
            $service->generate();

            $xml = file_get_contents($filePath);

            $this->assertStringContainsString('/church/services/2026-06-14/morning', $xml);
            $this->assertStringNotContainsString('/church/services/2026-06-14/evening', $xml);
            $this->assertStringContainsString(route('church.services.index'), $xml);
        } finally {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    private function stubbedSermonRepository(): SermonRepository
    {
        $sermonRepository = $this->createStub(SermonRepository::class);
        $sermonRepository->method('getSermonsByService')->willReturn(collect());
        $sermonRepository->method('getExistingSeries')->willReturn([]);
        $sermonRepository->method('getSeriesForDisplay')->willReturn([]);
        $sermonRepository->method('getExistingScriptureBooks')->willReturn(collect());

        return $sermonRepository;
    }
}
