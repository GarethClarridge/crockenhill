<?php

namespace Tests\Unit\Services;

use App\Services\TranscriptStorageService;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TranscriptStorageServiceTest extends TestCase
{
    private TranscriptStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['media-processing.storage.transcript_disk' => 'local']);
        Storage::fake();
        $this->service = new TranscriptStorageService;
    }

    // --- storeTranscript() ---

    #[Test]
    public function it_stores_transcript_and_returns_file_path(): void
    {
        $path = $this->service->storeTranscript(1, 'This is a transcript.');

        $this->assertEquals('transcripts/sermon_1.md', $path);
        Storage::assertExists('transcripts/sermon_1.md');
    }

    #[Test]
    public function it_uses_configured_transcript_disk_for_storage(): void
    {
        config(['media-processing.storage.transcript_disk' => 'do_spaces']);
        Storage::fake('do_spaces');

        $path = $this->service->storeTranscript(2, 'This is stored on configured disk.');

        $this->assertEquals('transcripts/sermon_2.md', $path);
        Storage::disk('do_spaces')->assertExists('transcripts/sermon_2.md');
    }

    #[Test]
    public function it_stores_transcript_content_correctly(): void
    {
        $content = "# Sermon Transcript\n\nThis is the full content.";

        $this->service->storeTranscript(42, $content);

        $this->assertEquals($content, Storage::get('transcripts/sermon_42.md'));
    }

    #[Test]
    public function it_creates_transcript_directory_if_not_exists(): void
    {
        $this->service->storeTranscript(1, 'Content');

        Storage::assertExists('transcripts');
    }

    // --- getTranscript() ---

    #[Test]
    public function it_retrieves_stored_transcript(): void
    {
        Storage::put('transcripts/sermon_5.md', 'Stored transcript content');

        $content = $this->service->getTranscript(5);

        $this->assertEquals('Stored transcript content', $content);
    }

    #[Test]
    public function it_returns_null_when_transcript_does_not_exist(): void
    {
        $content = $this->service->getTranscript(999);

        $this->assertNull($content);
    }

    // --- transcriptExists() ---

    #[Test]
    public function it_returns_true_when_transcript_exists(): void
    {
        Storage::put('transcripts/sermon_10.md', 'Content');

        $this->assertTrue($this->service->transcriptExists(10));
    }

    #[Test]
    public function it_returns_false_when_transcript_does_not_exist(): void
    {
        $this->assertFalse($this->service->transcriptExists(999));
    }

    // --- deleteTranscript() ---

    #[Test]
    public function it_deletes_existing_transcript(): void
    {
        Storage::put('transcripts/sermon_7.md', 'Content to delete');

        $result = $this->service->deleteTranscript(7);

        $this->assertTrue($result);
        Storage::assertMissing('transcripts/sermon_7.md');
    }

    #[Test]
    public function it_returns_true_when_deleting_nonexistent_transcript(): void
    {
        $result = $this->service->deleteTranscript(999);

        $this->assertTrue($result);
    }

    // --- cleanupOnFailure() ---

    #[Test]
    public function it_cleans_up_transcript_on_failure(): void
    {
        Storage::put('transcripts/sermon_15.md', 'Content');

        $this->service->cleanupOnFailure(15);

        Storage::assertMissing('transcripts/sermon_15.md');
    }

    // --- getTranscriptPath() ---

    #[Test]
    public function it_returns_correct_transcript_path(): void
    {
        $path = $this->service->getTranscriptPath(42);

        $this->assertEquals('transcripts/sermon_42.md', $path);
    }

    // --- round-trip test ---

    #[Test]
    public function it_completes_store_retrieve_delete_cycle(): void
    {
        $content = 'Full lifecycle transcript content';

        // Store
        $path = $this->service->storeTranscript(100, $content);
        $this->assertTrue($this->service->transcriptExists(100));

        // Retrieve
        $retrieved = $this->service->getTranscript(100);
        $this->assertEquals($content, $retrieved);

        // Delete
        $this->service->deleteTranscript(100);
        $this->assertFalse($this->service->transcriptExists(100));
        $this->assertNull($this->service->getTranscript(100));
    }
}
