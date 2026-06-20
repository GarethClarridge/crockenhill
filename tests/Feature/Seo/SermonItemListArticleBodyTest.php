<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Preacher;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use App\Seo\SermonItemListPresenter;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\Public\SermonRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonItemListArticleBodyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function preacher_sermon_list_json_ld_includes_article_body_from_summary(): void
    {
        $preacher = Preacher::factory()->create();
        Sermon::factory()->create([
            'preacher_id' => $preacher->id,
            'summary' => 'This is a test sermon summary.',
            'show_summary' => true,
        ]);

        $response = $this->get(route('sermons.preacher', $preacher->slug));

        $response->assertStatus(200);
        $response->assertSee('"articleBody": "This is a test sermon summary."', false);
    }

    #[Test]
    public function preacher_sermon_list_json_ld_includes_article_body_from_transcript(): void
    {
        Cache::flush();

        $preacher = Preacher::factory()->create();
        $sermon = Sermon::factory()->withAudio()->create([
            'preacher_id' => $preacher->id,
            'transcript_file_path' => 'transcripts/test.txt',
            'summary' => 'This should be overridden by transcript.',
            'show_summary' => true,
        ]);

        $mockReader = Mockery::mock(SermonTranscriptReader::class);
        $mockReader->shouldReceive('read')
            ->atLeast()->once()
            ->with(Mockery::on(fn ($arg) => (int) $arg->id === (int) $sermon->id))
            ->andReturn('This is the test transcript content.');
        $this->app->instance(SermonTranscriptReader::class, $mockReader);

        // Ensure fresh instances that will use the mocked reader
        $this->app->forgetInstance(SermonViewPresenter::class);
        $this->app->forgetInstance(SermonItemListPresenter::class);
        $this->app->forgetInstance(SermonRepository::class);

        $response = $this->get(route('sermons.preacher', $preacher->slug));

        $response->assertStatus(200);
        $response->assertSee('"articleBody": "This is the test transcript content."', false);
        $response->assertSee('"transcript": "This is the test transcript content."', false);
    }

    #[Test]
    public function series_sermon_list_json_ld_includes_article_body_from_summary(): void
    {
        $seriesName = 'Test Series';
        Sermon::factory()->create([
            'series' => $seriesName,
            'summary' => 'Series sermon summary.',
            'show_summary' => true,
        ]);

        $response = $this->get(route('sermons.series.show', Str::slug($seriesName)));

        $response->assertStatus(200);
        $response->assertSee('"articleBody": "Series sermon summary."', false);
    }
}
