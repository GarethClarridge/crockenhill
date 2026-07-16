<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Models\Meeting;
use App\Models\Page;
use App\Observers\PublicReadModelCacheObserver;
use App\Services\Public\PageImageCacheService;
use App\Services\Public\PublicMeetingReadModelCache;
use App\Services\Public\PublicPageReadModelCache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicReadModelCacheObserverTest extends TestCase
{
    #[Test]
    public function it_forgets_page_image_page_and_linked_meeting_read_models(): void
    {
        $meeting = new Meeting(['id' => 2]);
        $page = (new Page(['id' => 1]))->setRelation('meeting', $meeting);

        $pageImageCache = $this->createMock(PageImageCacheService::class);
        $pageImageCache->expects($this->once())->method('forget')->with($page);

        $pageReadModelCache = $this->createMock(PublicPageReadModelCache::class);
        $pageReadModelCache->expects($this->once())->method('forget')->with($page);

        $meetingReadModelCache = $this->createMock(PublicMeetingReadModelCache::class);
        $meetingReadModelCache->expects($this->once())->method('forget')->with($meeting);

        $observer = new PublicReadModelCacheObserver(
            $pageImageCache,
            $meetingReadModelCache,
            $pageReadModelCache,
        );

        $observer->updated($page);
    }

    #[Test]
    public function it_forgets_the_meeting_read_model_without_touching_page_caches(): void
    {
        $meeting = new Meeting(['id' => 2]);

        $pageImageCache = $this->createMock(PageImageCacheService::class);
        $pageImageCache->expects($this->never())->method('forget');

        $pageReadModelCache = $this->createMock(PublicPageReadModelCache::class);
        $pageReadModelCache->expects($this->never())->method('forget');

        $meetingReadModelCache = $this->createMock(PublicMeetingReadModelCache::class);
        $meetingReadModelCache->expects($this->once())->method('forget')->with($meeting);

        $observer = new PublicReadModelCacheObserver(
            $pageImageCache,
            $meetingReadModelCache,
            $pageReadModelCache,
        );

        $observer->updated($meeting);
    }
}
