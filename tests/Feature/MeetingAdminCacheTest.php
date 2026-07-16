<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Public\MeetingListCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingAdminCacheTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private MeetingListCache $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(MeetingListCache::class);
        Cache::flush();
    }

    #[Test]
    public function admin_meeting_list_cache_is_populated_on_request(): void
    {
        DB::enableQueryLog();

        // First call: executes queries
        $this->repository->forAdminList();
        $this->assertNotEmpty(DB::getQueryLog());

        DB::flushQueryLog();
        // Second call: hits cache, no queries
        $this->repository->forAdminList();
        $this->assertEmpty(DB::getQueryLog());
    }
}
