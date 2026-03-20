<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Sermon;
use App\Services\SermonTranscriptReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
