<?php

declare(strict_types=1);

namespace Tests\Integration\Presenters;

use App\Presenters\SongArchiveSeoPresenter;
use App\Services\PublicSongCatalogService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongArchiveSeoPresenterTest extends TestCase
{
    private SongArchiveSeoPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = app(SongArchiveSeoPresenter::class);
    }

    #[Test]
    public function title_returns_default_when_no_search(): void
    {
        $this->assertSame('Recent Songs', $this->presenter->title(null, PublicSongCatalogService::RANGE_RECENT));
        $this->assertSame('All Songs', $this->presenter->title(null, PublicSongCatalogService::RANGE_ALL));
    }

    #[Test]
    public function title_includes_search_term(): void
    {
        $this->assertSame('In Christ Alone | Songs', $this->presenter->title('In Christ Alone', PublicSongCatalogService::RANGE_ALL));
    }

    #[Test]
    public function title_includes_page_number_when_greater_than_one(): void
    {
        $this->assertSame('Recent Songs (Page 2)', $this->presenter->title(null, PublicSongCatalogService::RANGE_RECENT, 2));
        $this->assertSame('In Christ Alone | Songs (Page 3)', $this->presenter->title('In Christ Alone', PublicSongCatalogService::RANGE_ALL, 3));
    }

    #[Test]
    public function description_includes_page_number_when_greater_than_one(): void
    {
        $this->assertStringEndsWith('- Page 2', $this->presenter->description(null, PublicSongCatalogService::RANGE_RECENT, 2));
        $this->assertStringEndsWith('- Page 3', $this->presenter->description('Amazing Grace', PublicSongCatalogService::RANGE_ALL, 3));
    }
}
