<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\Sermon;
use App\Services\Media\Audio\SermonTranscriptReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonTranscriptReaderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reads_a_transcript_from_storage(): void
    {
        Storage::fake('local');
        Storage::put('transcripts/reader-test.md', 'Transcript content');

        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/reader-test.md',
        ]);

        $transcript = app(SermonTranscriptReader::class)->read($sermon);

        $this->assertSame('Transcript content', $transcript);
    }

    #[Test]
    public function it_rejects_path_traversal_attempts(): void
    {
        Log::spy();

        $sermon = Sermon::factory()->create([
            'transcript_file_path' => '../secrets.txt',
        ]);

        $transcript = app(SermonTranscriptReader::class)->read($sermon);

        $this->assertNull($transcript);
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function it_reads_from_the_sermons_own_asset_disk_before_the_candidate_list(): void
    {
        Storage::fake('local');
        Storage::fake('historic_quarantine');

        // Not on any generic candidate disk — only on the sermon's own asset
        // disk, as a promoted historic sermon's transcript actually is.
        Storage::disk('historic_quarantine')->put('transcripts/reader-test.md', 'Quarantined content');

        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/reader-test.md',
            'asset_disk' => 'historic_quarantine',
        ]);

        $transcript = app(SermonTranscriptReader::class)->read($sermon);

        $this->assertSame('Quarantined content', $transcript);
    }

    #[Test]
    public function it_returns_null_when_the_transcript_is_missing(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('do_spaces');
        Log::spy();

        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/missing-reader-test.md',
        ]);

        $transcript = app(SermonTranscriptReader::class)->read($sermon);

        $this->assertNull($transcript);
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function it_caches_successful_transcript_reads_for_24_hours(): void
    {
        Cache::spy();
        Storage::fake('local');
        Storage::put('transcripts/cache-test.md', 'Transcript content');

        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/cache-test.md',
        ]);

        $reader = app(SermonTranscriptReader::class);
        $reader->read($sermon);

        $hash = sha1('transcripts/cache-test.md');
        $timestamp = $sermon->updated_at->getTimestamp();
        $expectedKey = "sermon_transcript_{$hash}_{$timestamp}";

        Cache::shouldHaveReceived('put')
            ->once()
            ->with($expectedKey, 'Transcript content', 86400);
    }

    #[Test]
    public function it_uses_cached_transcript_on_subsequent_calls(): void
    {
        Storage::fake('local');
        Storage::put('transcripts/subsequent-test.md', 'Initial content');

        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/subsequent-test.md',
        ]);

        $reader = app(SermonTranscriptReader::class);

        // First read - should hit storage and cache
        $this->assertSame('Initial content', $reader->read($sermon));

        // Change content in storage
        Storage::put('transcripts/subsequent-test.md', 'Changed content');

        // Second read - should hit cache and NOT see the change
        $this->assertSame('Initial content', $reader->read($sermon));
    }

    #[Test]
    public function it_busts_cache_when_sermon_is_updated(): void
    {
        Storage::fake('local');
        Storage::put('transcripts/bust-test.md', 'Original content');

        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/bust-test.md',
            'updated_at' => now()->subMinutes(5),
        ]);

        $reader = app(SermonTranscriptReader::class);

        // First read - should hit storage and cache
        $this->assertSame('Original content', $reader->read($sermon));

        // Change content in storage
        Storage::put('transcripts/bust-test.md', 'Updated content');

        // Update the sermon timestamp to bust cache
        $sermon->updated_at = now();
        $sermon->save();
        $sermon->refresh();

        // Second read - should hit storage again because timestamp changed
        $this->assertSame('Updated content', $reader->read($sermon));
    }

    #[Test]
    public function it_does_not_cache_failed_reads(): void
    {
        Cache::spy();
        Storage::fake('local');

        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/fail-cache-test.md',
        ]);

        $reader = app(SermonTranscriptReader::class);
        $reader->read($sermon);

        Cache::shouldNotHaveReceived('put');
    }
}
