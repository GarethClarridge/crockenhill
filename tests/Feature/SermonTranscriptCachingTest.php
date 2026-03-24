<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Sermon;
use App\Services\SermonTranscriptReader;
use App\Services\TranscriptStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonTranscriptCachingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function it_caches_the_transcript_content(): void
    {
        $transcriptContent = 'This is a cached transcript content.';
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/cached-test.md',
        ]);

        $storageService = $this->mock(TranscriptStorageService::class);
        $storageService->shouldReceive('readTranscriptFromPath')
            ->once()
            ->with('transcripts/cached-test.md')
            ->andReturn($transcriptContent);

        $reader = new SermonTranscriptReader($storageService);

        // First call should hit storage and cache the result
        $result1 = $reader->read($sermon);
        $this->assertSame($transcriptContent, $result1);

        // Second call should return cached result without hitting storage
        $result2 = $reader->read($sermon);
        $this->assertSame($transcriptContent, $result2);
    }

    #[Test]
    public function it_invalidates_cache_when_sermon_is_updated(): void
    {
        $transcriptContent = 'Transcript content.';
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/invalidate-test.md',
        ]);

        $storageService = $this->mock(TranscriptStorageService::class);
        $storageService->shouldReceive('readTranscriptFromPath')
            ->twice()
            ->with('transcripts/invalidate-test.md')
            ->andReturn($transcriptContent);

        $reader = new SermonTranscriptReader($storageService);

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

    #[Test]
    public function it_invalidates_cache_when_path_changes(): void
    {
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/path1.md',
        ]);

        $storageService = $this->mock(TranscriptStorageService::class);

        $storageService->shouldReceive('readTranscriptFromPath')
            ->once()
            ->with('transcripts/path1.md')
            ->andReturn('Content 1');

        $storageService->shouldReceive('readTranscriptFromPath')
            ->once()
            ->with('transcripts/path2.md')
            ->andReturn('Content 2');

        $reader = new SermonTranscriptReader($storageService);

        // First call with path1
        $this->assertSame('Content 1', $reader->read($sermon));

        // Change the path on the same sermon instance
        $sermon->transcript_file_path = 'transcripts/path2.md';

        // Second call with path2 should hit storage again
        $this->assertSame('Content 2', $reader->read($sermon));
    }
}
