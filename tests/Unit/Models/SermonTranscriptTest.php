<?php

namespace Tests\Unit\Models;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonTranscriptTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function get_transcript_returns_null_when_no_path_set(): void
    {
        $sermon = Sermon::factory()->make(['transcript_file_path' => null]);
        $this->assertNull($sermon->transcript);

        $sermon = Sermon::factory()->make(['transcript_file_path' => '']);
        $this->assertNull($sermon->transcript);
    }

    #[Test]
    public function get_transcript_reads_from_primary_disk(): void
    {
        Storage::fake('transcripts');
        Config::set('media-processing.storage.transcript_disk', 'transcripts');

        $path = 'sermons/transcript.md';
        $content = 'This is the transcript content.';
        Storage::disk('transcripts')->put($path, $content);

        $sermon = Sermon::factory()->make(['transcript_file_path' => $path]);

        $this->assertEquals($content, $sermon->transcript);
    }

    #[Test]
    public function get_transcript_falls_back_to_other_disks(): void
    {
        Storage::fake('transcripts');
        Storage::fake('sermons');

        Config::set('media-processing.storage.transcript_disk', 'transcripts');
        Config::set('media-processing.storage.sermon_disk', 'sermons');

        $path = 'fallback/transcript.md';
        $content = 'Fallback content';

        // Missing on primary, exists on secondary
        Storage::disk('sermons')->put($path, $content);

        $sermon = Sermon::factory()->make(['transcript_file_path' => $path]);

        $this->assertEquals($content, $sermon->transcript);
    }

    #[Test]
    public function get_transcript_logs_warning_and_returns_null_when_not_found_on_any_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->withAnyArgs();

        $sermon = Sermon::factory()->make(['transcript_file_path' => 'missing.md']);

        $this->assertNull($sermon->transcript);
    }

    #[Test]
    public function has_transcript_returns_correct_boolean(): void
    {
        $sermon = Sermon::factory()->make(['transcript_file_path' => 'path/to/transcript.md']);
        $this->assertTrue($sermon->hasTranscript());

        $sermon = Sermon::factory()->make(['transcript_file_path' => null]);
        $this->assertFalse($sermon->hasTranscript());

        $sermon = Sermon::factory()->make(['transcript_file_path' => '']);
        $this->assertFalse($sermon->hasTranscript());

        $sermon = Sermon::factory()->make(['transcript_file_path' => '   ']);
        $this->assertFalse($sermon->hasTranscript());
    }

    #[Test]
    public function is_automated_checks_transcript_path_and_logs(): void
    {
        // Only transcript path
        $sermon = Sermon::factory()->make(['transcript_file_path' => 'path.md']);
        $this->assertTrue($sermon->isAutomated());

        // Only processing logs
        $sermon = Sermon::factory()->create(['transcript_file_path' => null]);
        MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);
        $this->assertTrue($sermon->isAutomated());

        // Both
        $sermon = Sermon::factory()->create(['transcript_file_path' => 'path.md']);
        MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);
        $this->assertTrue($sermon->isAutomated());

        // Neither
        $sermon = Sermon::factory()->create(['transcript_file_path' => null]);
        $this->assertFalse($sermon->isAutomated());
    }

    #[Test]
    public function is_manual_is_inverse_of_is_automated(): void
    {
        $sermon = Sermon::factory()->make(['transcript_file_path' => 'path.md']);
        $this->assertFalse($sermon->isManual());

        $sermon = Sermon::factory()->create(['transcript_file_path' => null]);
        $this->assertTrue($sermon->isManual());
    }
}
