<?php

declare(strict_types=1);

namespace Tests\Integration\Presenters;

use App\Models\Meeting;
use App\Presenters\MeetingShowPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingShowPresenterTest extends TestCase
{
    use RefreshDatabase;

    private MeetingShowPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new MeetingShowPresenter;
    }

    #[Test]
    public function photos_returns_a_plain_support_collection_when_no_media_are_attached(): void
    {
        // Spatie's MediaLibrary returns a MediaCollection subclass from getMedia().
        // That subclass is not on the cache.serializable_classes allow-list, so if
        // it leaks into the cached PublicMeetingReadModel the next read crashes
        // with __PHP_Incomplete_Class. The presenter must always return a base
        // Illuminate\Support\Collection.
        $meeting = Meeting::factory()->create();

        $result = $this->presenter->photos($meeting);

        $this->assertSame(Collection::class, $result::class);
        $this->assertCount(0, $result);
    }
}
