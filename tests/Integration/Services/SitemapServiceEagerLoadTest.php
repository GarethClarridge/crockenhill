<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonContentType;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SitemapServiceEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function representative_sermon_query_for_series_eager_loads_preacher_profile(): void
    {
        $preacher = Preacher::factory()->create();
        Sermon::factory()->count(3)->withPreacher($preacher)->inSeries('Test Series')->create();

        $subquery = Sermon::query()
            ->whereSermon()
            ->whereNotNull('series')
            ->where('series', '!=', '')
            ->select([
                'id', 'title', 'date', 'slug', 'series',
                'thumbnail_file_path', 'thumbnail_generated_at', 'thumbnail_metadata',
                'video_file_path', 'video_visibility_override', 'video_quality_status',
                'content_type', 'updated_at', 'preacher', 'preacher_id',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY series ORDER BY date DESC, id DESC) as row_num');

        $results = Sermon::query()
            ->fromSub($subquery, 'ranked')
            ->with(['preacherProfile:id,name,slug,image_path'])
            ->where('row_num', 1)
            ->get();

        $this->assertNotEmpty($results);

        DB::enableQueryLog();

        foreach ($results as $sermon) {
            $this->assertTrue(
                $sermon->relationLoaded('preacherProfile'),
                "preacherProfile should be eager-loaded on sermon {$sermon->id}, not lazy-loaded",
            );
            // Accessing the relation must not trigger a new query
            $_ = $sermon->preacherProfile;
        }

        $this->assertEmpty(DB::getQueryLog(), 'Accessing preacherProfile triggered N+1 queries — eager load is not working');
    }

    #[Test]
    public function representative_sermon_query_for_books_eager_loads_preacher_profile(): void
    {
        $preacher = Preacher::factory()->create();
        Sermon::factory()->count(2)->withPreacher($preacher)->create([
            'content_type' => SermonContentType::Sermon,
        ]);

        $subquery = Sermon::query()
            ->join('sermon_scripture_filters', 'sermons.id', '=', 'sermon_scripture_filters.sermon_id')
            ->whereSermon()
            ->select([
                'sermons.id', 'sermons.title', 'sermons.date', 'sermons.slug',
                'sermons.thumbnail_file_path', 'sermons.thumbnail_generated_at', 'sermons.thumbnail_metadata',
                'sermons.video_file_path', 'sermons.video_visibility_override', 'sermons.video_quality_status',
                'sermons.content_type', 'sermons.updated_at', 'sermons.preacher', 'sermons.preacher_id',
                'sermon_scripture_filters.bible_book as book_group',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY sermon_scripture_filters.bible_book ORDER BY sermons.date DESC, sermons.id DESC) as row_num');

        $results = Sermon::query()
            ->fromSub($subquery, 'ranked')
            ->with(['preacherProfile:id,name,slug,image_path'])
            ->where('row_num', 1)
            ->get();

        DB::enableQueryLog();

        foreach ($results as $sermon) {
            $this->assertTrue(
                $sermon->relationLoaded('preacherProfile'),
                "preacherProfile should be eager-loaded on sermon {$sermon->id}",
            );
            $_ = $sermon->preacherProfile;
        }

        $this->assertEmpty(DB::getQueryLog(), 'Accessing preacherProfile triggered N+1 queries — eager load is not working');
    }
}
