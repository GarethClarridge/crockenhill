<?php

namespace Tests\Unit;

use App\Data\SpeakerMatchResult;
use App\Models\Preacher;
use App\Models\SpeakerProfile;
use App\Services\ResemblyzerSpeakerIdentificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResemblyzerSpeakerIdentificationServiceTest extends TestCase
{
    use RefreshDatabase;

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

    // -------------------------------------------------------------------------
    // TD-005B: Transient infrastructure failures vs. deterministic no-match outcomes
    //
    // Policy:
    //   - Transient (process crash, timeout, storage error): surface via errored=true
    //     result so the job can log and not block the pipeline. $tries=1 means no
    //     in-process retry; the caller decides whether to alert or silently skip.
    //   - Deterministic (below threshold, low margin): surface via matched=false
    //     result with a clear reason — no retry is appropriate.
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_error_result_when_audio_file_missing(): void
    {
        // Storage failure (file not found) is a transient infrastructure problem —
        // the result must be errored=true so the job does not mark processing as failed.
        $result = $this->service->extractEmbedding('sermons/audio/nonexistent.mp3');

        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('not found', $result->errorMessage);
    }

    #[Test]
    public function it_returns_error_result_when_extraction_script_missing(): void
    {
        // Missing Python script is an infrastructure failure — errored result, not exception.
        Storage::disk('public')->put('sermons/audio/test.mp3', 'fake audio');
        config(['media-processing.speaker_identification.script_path' => '/nonexistent/script.py']);

        $this->service = new ResemblyzerSpeakerIdentificationService;

        $result = $this->service->extractEmbedding('sermons/audio/test.mp3');

        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('script not found', $result->errorMessage);
    }

    #[Test]
    public function it_returns_error_result_when_process_exits_non_zero(): void
    {
        // A non-zero exit code from the Python process is a transient infrastructure
        // failure (e.g., OOM, missing dependency) — distinct from a valid "no match".
        Storage::disk('public')->put('sermons/audio/test.mp3', 'fake audio');

        Process::fake([
            '*' => Process::result(output: '', exitCode: 1),
        ]);

        $result = $this->service->extractEmbedding('sermons/audio/test.mp3');

        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('Extraction script failed', $result->errorMessage);
    }

    #[Test]
    public function identify_returns_error_result_when_embedding_extraction_fails(): void
    {
        // When extractEmbedding() returns a failed result, identify() must propagate
        // it as SpeakerMatchResult::error() — not throw an exception.
        Storage::disk('public')->put('sermons/audio/test.mp3', 'fake audio');

        Process::fake([
            '*' => Process::result(output: '', exitCode: 1),
        ]);

        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id]);
        $profiles = new Collection([$profile]);

        $result = $this->service->identify('sermons/audio/test.mp3', $profiles);

        $this->assertTrue($result->errored);
        $this->assertFalse($result->matched);
    }

    #[Test]
    public function identify_returns_no_match_result_below_accept_threshold(): void
    {
        // A score below the accept threshold is a deterministic outcome (the speaker
        // just does not match) — no retry is appropriate. Result: matched=false, errored=false.
        // Use orthogonal vectors (cosine similarity = 0.0) to exercise the threshold
        // comparison path rather than the degenerate zero-norm guard.
        Storage::disk('public')->put('sermons/audio/test.mp3', 'fake audio');

        // Alternating +1/-1 embedding — orthogonal to the all-ones centroid below,
        // so cosine similarity is 0.0 via the normal dot-product path, not the norm guard.
        $embedding = [];
        for ($i = 0; $i < 256; $i++) {
            $embedding[] = ($i % 2 === 0) ? 1.0 : -1.0;
        }

        Process::fake([
            '*' => Process::result(json_encode([
                'embedding' => $embedding,
                'duration_used' => 60.0,
            ])),
        ]);

        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'centroid_embedding' => array_fill(0, 256, 1.0),
            'accept_threshold' => 0.85,
        ]);

        $profiles = new Collection([$profile]);
        $result = $this->service->identify('sermons/audio/test.mp3', $profiles);

        $this->assertFalse($result->errored);
        $this->assertFalse($result->matched);
        $this->assertNotNull($result->reason);
    }

    #[Test]
    public function identify_returns_matched_result_above_threshold(): void
    {
        // A score above both accept and margin thresholds is a deterministic match.
        Storage::disk('public')->put('sermons/audio/test.mp3', 'fake audio');

        // Embedding identical to profile centroid → cosine similarity = 1.0.
        $centroid = array_fill(0, 256, 0.5);
        Process::fake([
            '*' => Process::result(json_encode([
                'embedding' => $centroid,
                'duration_used' => 60.0,
            ])),
        ]);

        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'centroid_embedding' => $centroid,
            'accept_threshold' => 0.80,
            'margin_threshold' => 0.10,
        ]);

        $profiles = new Collection([$profile]);
        $result = $this->service->identify('sermons/audio/test.mp3', $profiles);

        $this->assertFalse($result->errored);
        $this->assertTrue($result->matched);
        $this->assertEquals($preacher->id, $result->matchedPreacherId);
    }
}
