<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\TranscriptStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TranscriptStorageServiceTest extends TestCase
{
    use RefreshDatabase;

    private TranscriptStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'filesystems.disks.do_spaces.bucket' => null,
            'media-processing.storage.transcript_disk' => 'local',
        ]);
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
    public function it_uses_the_processing_id_for_a_historic_import_transcript(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'processing_metadata' => [
                'historic_import' => ['label' => 'archive recording'],
            ],
        ]);

        $path = $this->service->storeTranscript(42, 'Historic transcript', $log->processing_id);

        $this->assertSame("historic-imports/{$log->processing_id}/sermon/transcript.md", $path);
        $this->assertStringNotContainsString('sermon_42', $path);
        Storage::assertExists($path);
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

    // --- getTranscriptReadDisks() ---

    #[Test]
    public function it_returns_configured_disks_in_priority_order(): void
    {
        config([
            'media-processing.storage.transcript_disk' => 'do_spaces',
            'media-processing.storage.sermon_disk' => 'public',
            'filesystems.default' => 'local',
        ]);

        $disks = $this->service->getTranscriptReadDisks();

        // Configured disks appear before hardcoded fallbacks; duplicates are removed
        $this->assertSame(['do_spaces', 'public', 'local'], $disks);
    }

    #[Test]
    public function it_deduplicates_disk_candidates(): void
    {
        config([
            'media-processing.storage.transcript_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'local',
            'filesystems.default' => 'local',
        ]);

        $disks = $this->service->getTranscriptReadDisks();

        $this->assertSame(['local', 'public'], $disks);
    }

    #[Test]
    public function it_includes_spaces_fallback_when_bucket_is_configured(): void
    {
        config([
            'filesystems.disks.do_spaces.bucket' => 'configured-bucket',
            'media-processing.storage.transcript_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'local',
            'filesystems.default' => 'local',
        ]);

        $disks = $this->service->getTranscriptReadDisks();

        $this->assertSame(['local', 'public', 'do_spaces'], $disks);
    }

    // --- readTranscriptFromPath() ---

    #[Test]
    public function it_reads_transcript_from_primary_disk(): void
    {
        Storage::fake('primary');
        config(['media-processing.storage.transcript_disk' => 'primary']);

        Storage::disk('primary')->put('transcripts/sermon_1.md', 'Primary content');

        $result = $this->service->readTranscriptFromPath('transcripts/sermon_1.md');

        $this->assertSame('Primary content', $result);
    }

    #[Test]
    public function it_falls_back_to_next_disk_when_primary_missing(): void
    {
        Storage::fake('primary');
        Storage::fake('fallback');
        config([
            'media-processing.storage.transcript_disk' => 'primary',
            'media-processing.storage.sermon_disk' => 'fallback',
        ]);

        Storage::disk('fallback')->put('transcripts/sermon_2.md', 'Fallback content');

        $result = $this->service->readTranscriptFromPath('transcripts/sermon_2.md');

        $this->assertSame('Fallback content', $result);
    }

    #[Test]
    public function it_returns_null_when_path_is_empty(): void
    {
        $this->assertNull($this->service->readTranscriptFromPath(''));
        $this->assertNull($this->service->readTranscriptFromPath('   '));
    }

    #[Test]
    public function it_returns_null_when_not_found_on_any_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('do_spaces');

        $result = $this->service->readTranscriptFromPath('transcripts/missing.md');

        $this->assertNull($result);
    }

    #[Test]
    public function it_skips_unconfigured_spaces_fallback_when_transcript_is_missing(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config([
            'filesystems.disks.do_spaces.bucket' => null,
            'media-processing.storage.transcript_disk' => 'public',
            'media-processing.storage.sermon_disk' => 'public',
            'filesystems.default' => 'local',
        ]);

        $result = $this->service->readTranscriptFromPath('transcripts/missing.md');

        $this->assertNull($result);
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
