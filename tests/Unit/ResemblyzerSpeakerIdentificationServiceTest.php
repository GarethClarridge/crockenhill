<?php

namespace Tests\Unit;

use App\Services\ResemblyzerSpeakerIdentificationService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResemblyzerSpeakerIdentificationServiceTest extends TestCase
{
    private ResemblyzerSpeakerIdentificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.speaker_identification.script_path' => base_path('scripts/extract_embedding.py'),
        ]);

        $this->service = new ResemblyzerSpeakerIdentificationService;
    }

    #[Test]
    public function it_parses_embedding_json_after_info_lines(): void
    {
        Storage::disk('public')->put('sermons/audio/test.mp3', 'fake audio');

        Process::fake([
            '*' => Process::result("Loaded the voice encoder model on cpu in 0.02 seconds.\n{\"embedding\":[0.1,0.2],\"duration_used\":60.0}\n"),
        ]);

        $result = $this->service->extractEmbedding('sermons/audio/test.mp3');

        $this->assertTrue($result->success);
        $this->assertSame([0.1, 0.2], $result->embedding);
        $this->assertSame(60.0, $result->durationUsed);
        $this->assertNull($result->errorMessage);
    }

    #[Test]
    public function it_fails_when_script_output_contains_no_json(): void
    {
        Storage::disk('public')->put('sermons/audio/test.mp3', 'fake audio');

        Process::fake([
            '*' => Process::result("Loaded the voice encoder model on cpu in 0.02 seconds.\n"),
        ]);

        $result = $this->service->extractEmbedding('sermons/audio/test.mp3');

        $this->assertFalse($result->success);
        $this->assertNull($result->embedding);
        $this->assertSame('Invalid extraction script output', $result->errorMessage);
    }
}
