<?php

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_populates_timestamps_automatically()
    {
        $sermon = Sermon::factory()->create();

        $this->assertNotNull($sermon->created_at);
        $this->assertNotNull($sermon->updated_at);
    }

    #[Test]
    public function it_accepts_long_audio_file_paths()
    {
        $longPath = 'sermons/'.str_repeat('a', 200).'.mp3';

        $sermon = Sermon::factory()->create([
            'audio_file_path' => $longPath,
        ]);

        $this->assertEquals($longPath, $sermon->audio_file_path);
    }

    #[Test]
    public function it_sets_livestream_processing_id_to_null_when_log_is_deleted()
    {
        $log = \App\Models\MediaProcessingLog::factory()->create([
            'processing_id' => \Illuminate\Support\Str::uuid()->toString(),
        ]);

        $sermon = Sermon::factory()->create([
            'livestream_processing_id' => $log->processing_id,
        ]);

        $this->assertEquals($log->processing_id, $sermon->livestream_processing_id);

        $log->delete();

        $sermon->refresh();
        $this->assertNull($sermon->livestream_processing_id);
    }
}
