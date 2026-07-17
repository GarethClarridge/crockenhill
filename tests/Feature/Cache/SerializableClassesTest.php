<?php

declare(strict_types=1);

namespace Tests\Feature\Cache;

use App\Data\PodcastFeedItemReadModel;
use App\Data\PublicMeetingReadModel;
use App\Data\PublicPageReadModel;
use App\Enums\PageArea;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

/**
 * Pins the cache deserialization allow-list configured in `config/cache.php`.
 *
 * The allow-list is enforced by stores that round-trip values through PHP's
 * `unserialize()` (file, database, redis, memcached). The array store used by
 * the default test environment skips serialization entirely, so we spin up a
 * dedicated FileStore here to exercise the real `allowed_classes` behaviour.
 */
class SerializableClassesTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cachePath = storage_path('framework/testing/serializable-classes-'.uniqid());

        if (! is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cachePath)) {
            (new Filesystem)->deleteDirectory($this->cachePath);
        }

        parent::tearDown();
    }

    #[Test]
    public function allow_list_is_defined_and_non_empty(): void
    {
        $allowList = config('cache.serializable_classes');

        $this->assertIsArray($allowList);
        $this->assertNotEmpty($allowList, 'cache.serializable_classes must be configured.');
    }

    #[Test]
    public function public_meeting_read_model_round_trips_through_cache(): void
    {
        $model = new PublicMeetingReadModel(
            area: 'community',
            content: 'body',
            description: 'desc',
            heading: 'Heading',
            headingpicture: null,
            headingpictureMobile: null,
            headingpictureTablet: null,
            metaDescription: 'meta',
            pageDescription: null,
            photos: collect(),
            slug: 'slug',
            upcomingEvents: collect(),
        );

        $cache = $this->serializingCache();
        $cache->put('meeting', $model, 60);

        $retrieved = $cache->get('meeting');

        $this->assertInstanceOf(PublicMeetingReadModel::class, $retrieved);
        $this->assertSame('Heading', $retrieved->heading);
    }

    #[Test]
    public function public_page_read_model_round_trips_through_cache(): void
    {
        $model = new PublicPageReadModel(
            area: PageArea::Church->value,
            content: 'body',
            description: 'desc',
            heading: 'Heading',
            headingpicture: null,
            headingpictureMobile: null,
            headingpictureTablet: null,
            metaDescription: 'meta',
            slug: 'slug',
        );

        $cache = $this->serializingCache();
        $cache->put('page', $model, 60);

        $retrieved = $cache->get('page');

        $this->assertInstanceOf(PublicPageReadModel::class, $retrieved);
        $this->assertSame('Heading', $retrieved->heading);
    }

    #[Test]
    public function podcast_feed_item_read_model_round_trips_through_cache(): void
    {
        $item = new PodcastFeedItemReadModel(
            canonicalUrl: 'https://example.com/sermons/amazing-grace',
            enclosureLength: 12345678,
            enclosureUrl: 'https://cdn.example.com/sermons/42.mp3',
            episodeImageUrl: null,
            itunesDuration: '00:45:30',
            podcastSummary: 'A sermon on grace.',
            preacherName: 'Mark Drury',
            publishedAt: 'Sun, 10 Mar 2024 10:30:00 +0000',
            sermonId: 42,
            title: 'Amazing Grace',
            transcriptUrl: null,
        );

        $cache = $this->serializingCache();
        $cache->put('podcast-item', collect([$item]), 60);

        $retrieved = $cache->get('podcast-item')->first();

        $this->assertInstanceOf(PodcastFeedItemReadModel::class, $retrieved);
        $this->assertSame('Amazing Grace', $retrieved->title);
        $this->assertSame('Mark Drury', $retrieved->preacherName);
    }

    #[Test]
    public function classes_outside_the_allow_list_are_rejected(): void
    {
        $cache = $this->serializingCache();

        $disallowed = new stdClass;
        $disallowed->name = 'should not survive';

        $cache->put('disallowed', $disallowed, 60);

        $retrieved = $cache->get('disallowed');

        $this->assertNotInstanceOf(stdClass::class, $retrieved);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $retrieved);
    }

    private function serializingCache(): Repository
    {
        return new Repository(new FileStore(
            new Filesystem,
            $this->cachePath,
            null,
            config('cache.serializable_classes'),
        ));
    }
}
