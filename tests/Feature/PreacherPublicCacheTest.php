<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\Public\SermonRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherPublicCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    #[Test]
    public function preacher_public_list_is_cached_when_visiting_index(): void
    {
        Preacher::factory()->create(['is_active' => true]);

        DB::enableQueryLog();

        // 1. Initial hit - should trigger queries
        DB::flushQueryLog();
        $this->get('/christ/sermons/preachers')->assertOk();
        $this->assertNotEmpty(DB::getQueryLog(), 'Expected the first request to trigger database queries.');

        // 2. Second hit - should NOT trigger preacher queries
        DB::flushQueryLog();
        $this->get('/christ/sermons/preachers')->assertOk();

        $queries = collect(DB::getQueryLog());
        $preacherQueries = $queries->filter(fn ($q) => str_contains($q['query'], 'preachers'));

        $this->assertEmpty($preacherQueries, 'Expected the second request to be served from cache without queries to preachers table.');
    }

    #[Test]
    public function preacher_sermon_cache_survives_allow_listed_unserialization(): void
    {
        // SermonRepository caches the preacher's sermons via Cache::flexible. In
        // production (Redis) that value is serialized and rehydrated under the
        // cache.serializable_classes allow-list; a separate worker serves the
        // next request, so Sermon (and its eager-loaded ScripturePassage) must be
        // allow-listed or the preacher page 500s with __PHP_Incomplete_Class.
        //
        // The array test driver does not serialise cached values, so round-trip the
        // cached collection directly instead.
        $preacher = Preacher::factory()->create(['is_active' => true]);
        $passage = ScripturePassage::factory()->create();
        Sermon::factory()->withPreacher($preacher)->create([
            'scripture_passage_id' => $passage->id,
            'date' => now(),
        ]);

        $sermons = app(SermonRepository::class)->getSermonsByPreacher($preacher);
        $this->assertNotEmpty($sermons, 'Expected the preacher to have a cached sermon to exercise.');

        $restored = unserialize(
            serialize($sermons),
            ['allowed_classes' => config('cache.serializable_classes')],
        );

        // print_r reveals nested incomplete classes; serialize() would not, because
        // an incomplete object re-emits its original class name.
        $this->assertStringNotContainsString('__PHP_Incomplete_Class', print_r($restored, true));
    }
}
