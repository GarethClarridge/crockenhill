<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\Media\Audio\TranscriptStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SermonTranscriptCachingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_it_caches_the_transcript_content(): void
    {
        $transcriptContent = 'This is a cached transcript content.';
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/cached-test.md',
        ]);
        assert($sermon instanceof Sermon);

        $this->mock(TranscriptStorageService::class, function ($mock) use ($transcriptContent) {
            $mock->shouldReceive('readTranscriptFromPath')
                ->once()
                ->with('transcripts/cached-test.md', null)
                ->andReturn($transcriptContent);
        });

        $this->app->forgetInstance(SermonTranscriptReader::class);
        $reader = app(SermonTranscriptReader::class);

        // First call should hit storage and cache the result
        $result1 = $reader->read($sermon);
        $this->assertSame($transcriptContent, $result1);

        // Second call should return cached result without hitting storage
        $result2 = $reader->read($sermon);
        $this->assertSame($transcriptContent, $result2);
    }

    public function test_it_invalidates_cache_when_sermon_is_updated(): void
    {
        $transcriptContent = 'Transcript content.';
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/invalidate-test.md',
        ]);
        assert($sermon instanceof Sermon);

        $this->mock(TranscriptStorageService::class, function ($mock) use ($transcriptContent) {
            $mock->shouldReceive('readTranscriptFromPath')
                ->twice()
                ->with('transcripts/invalidate-test.md', null)
                ->andReturn($transcriptContent);
        });

        $this->app->forgetInstance(SermonTranscriptReader::class);
        $reader = app(SermonTranscriptReader::class);

        // First call
        $reader->read($sermon);

        // Update the sermon to change its updated_at timestamp
        // Ensure we wait at least a second or manually set a different timestamp
        // to guarantee the cache key (which uses getTimestamp()) changes.
        $sermon->updated_at = now()->addSecond();
        $sermon->save();

        // Second call should hit storage again because the cache key changed
        $reader->read($sermon);
    }

    public function test_it_invalidates_cache_when_path_changes(): void
    {
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/path1.md',
        ]);
        assert($sermon instanceof Sermon);

        $this->mock(TranscriptStorageService::class, function ($mock) {
            $mock->shouldReceive('readTranscriptFromPath')
                ->once()
                ->with('transcripts/path1.md', null)
                ->andReturn('Content 1');

            $mock->shouldReceive('readTranscriptFromPath')
                ->once()
                ->with('transcripts/path2.md', null)
                ->andReturn('Content 2');
        });

        $this->app->forgetInstance(SermonTranscriptReader::class);
        $reader = app(SermonTranscriptReader::class);

        // First call with path1
        $this->assertSame('Content 1', $reader->read($sermon));

        // Change the path on the same sermon instance
        $sermon->transcript_file_path = 'transcripts/path2.md';

        // Second call with path2 should hit storage again
        $this->assertSame('Content 2', $reader->read($sermon));
    }

    public function test_it_does_not_cache_null_results_permanently(): void
    {
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/null-test.md',
        ]);
        assert($sermon instanceof Sermon);

        $this->mock(TranscriptStorageService::class, function ($mock) {
            // First call fails (returns null)
            $mock->shouldReceive('readTranscriptFromPath')
                ->once()
                ->with('transcripts/null-test.md', null)
                ->andReturn(null);

            // Called when logging the null result warning
            $mock->shouldReceive('getTranscriptReadDisks')
                ->once()
                ->andReturn([]);

            // Second call succeeds
            $mock->shouldReceive('readTranscriptFromPath')
                ->once()
                ->with('transcripts/null-test.md', null)
                ->andReturn('Recovered content');
        });

        $this->app->forgetInstance(SermonTranscriptReader::class);
        $reader = app(SermonTranscriptReader::class);

        // First call should return null
        $this->assertNull($reader->read($sermon));

        // Second call should NOT be cached as null and should hit storage again
        $this->assertSame('Recovered content', $reader->read($sermon));
    }
}
