<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonTranscriptReader;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonTranscriptTest extends TestCase
{
    use DatabaseTransactions;

    private SermonTranscriptReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure all common candidate disks are faked to avoid hitting real storage
        // and to prevent exceptions during candidate disk traversal.
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('do_spaces');

        // Set predictable base configuration for candidate disks
        Config::set('media-processing.storage.transcript_disk', 'local');
        Config::set('media-processing.storage.sermon_disk', 'public');
        $this->reader = app(SermonTranscriptReader::class);
    }

    #[Test]
    public function get_transcript_returns_null_when_no_path_set(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['transcript_file_path' => null]);
        $this->assertNull($this->reader->read($sermon));

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['transcript_file_path' => '']);
        $this->assertNull($this->reader->read($sermon));
    }

    #[Test]
    public function get_transcript_reads_from_primary_disk(): void
    {
        Storage::fake('transcripts');
        Config::set('media-processing.storage.transcript_disk', 'transcripts');

        $path = 'sermons/transcript.md';
        $content = 'This is the transcript content.';
        Storage::disk('transcripts')->put($path, $content);

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['transcript_file_path' => $path]);

        $this->assertEquals($content, $this->reader->read($sermon));
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

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['transcript_file_path' => $path]);

        $this->assertEquals($content, $this->reader->read($sermon));
    }

    #[Test]
    public function get_transcript_logs_warning_and_returns_null_when_not_found_on_any_disk(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('Transcript file not found on any configured disk', \Mockery::on(function ($args) {
                return ($args['transcript_file_path'] ?? '') === 'missing.md';
            }));

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['transcript_file_path' => 'missing.md']);

        $this->assertNull($this->reader->read($sermon));
    }

    #[Test]
    public function has_transcript_returns_correct_boolean(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['transcript_file_path' => 'path/to/transcript.md']);
        $this->assertTrue($sermon->hasTranscript());

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['transcript_file_path' => null]);
        $this->assertFalse($sermon->hasTranscript());

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['transcript_file_path' => '']);
        $this->assertFalse($sermon->hasTranscript());

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['transcript_file_path' => '   ']);
        $this->assertFalse($sermon->hasTranscript());
    }

    #[Test]
    public function is_automated_checks_transcript_path_and_logs(): void
    {
        // Only transcript path
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['transcript_file_path' => 'path.md']);
        $this->assertTrue($sermon->isAutomated());

        // Only processing logs
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create(['transcript_file_path' => null]);
        MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);
        $this->assertTrue($sermon->isAutomated());

        // Both
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create(['transcript_file_path' => 'path.md']);
        MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);
        $this->assertTrue($sermon->isAutomated());

        // Neither
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create(['transcript_file_path' => null]);
        $this->assertFalse($sermon->isAutomated());
    }

    #[Test]
    public function is_manual_is_inverse_of_is_automated(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['transcript_file_path' => 'path.md']);
        $this->assertFalse($sermon->isManual());

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create(['transcript_file_path' => null]);
        $this->assertTrue($sermon->isManual());
    }
}
