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
}
