<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\Song;
use App\Models\SongVideo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongVideoIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_validates_required_fields(): void
    {
        $rules = SongVideo::validationRules();

        $validator = Validator::make([], $rules);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('song_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('video_file_path', $validator->errors()->toArray());
        $this->assertArrayHasKey('is_featured', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_video_file_path_length(): void
    {
        $rules = SongVideo::validationRules();

        $validator = Validator::make([
            'video_file_path' => str_repeat('a', 501),
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('video_file_path', $validator->errors()->toArray());
    }

    #[Test]
    public function it_trims_video_file_path_via_attribute_setter(): void
    {
        $song = Song::factory()->create();
        $songVideo = new SongVideo([
            'song_id' => $song->id,
            'video_file_path' => '  path/to/video.mp4  ',
            'is_featured' => false,
        ]);

        $this->assertEquals('path/to/video.mp4', $songVideo->video_file_path);
    }

    #[Test]
    public function database_constraint_prevents_empty_video_file_path(): void
    {
        $song = Song::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('song_videos_video_file_path_format_check');

        // Use raw DB insert to bypass model attribute setters
        DB::table('song_videos')->insert([
            'song_id' => $song->id,
            'video_file_path' => '',
            'is_featured' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function database_constraint_prevents_untrimmed_video_file_path(): void
    {
        $song = Song::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('song_videos_video_file_path_format_check');

        // Use raw DB insert to bypass model attribute setters
        DB::table('song_videos')->insert([
            'song_id' => $song->id,
            'video_file_path' => ' untrimmed/path.mp4 ',
            'is_featured' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_allows_valid_video_file_path(): void
    {
        $song = Song::factory()->create();

        $songVideo = SongVideo::query()->create([
            'song_id' => $song->id,
            'video_file_path' => 'valid/path.mp4',
            'is_featured' => true,
        ]);

        $this->assertDatabaseHas('song_videos', [
            'id' => $songVideo->id,
            'video_file_path' => 'valid/path.mp4',
        ]);
    }
}
