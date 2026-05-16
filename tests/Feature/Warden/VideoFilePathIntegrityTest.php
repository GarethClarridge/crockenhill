<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\Song;
use App\Models\SongVideo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoFilePathIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function sermon_video_file_path_must_be_trimmed_in_validation(): void
    {
        $invalidPaths = [' ', ' Leading', 'Trailing '];

        foreach ($invalidPaths as $path) {
            $validator = Validator::make(
                ['video_file_path' => $path],
                Sermon::validationRules()
            );

            $this->assertTrue($validator->fails(), "Validation should have failed for sermon path: '{$path}'");
        }
    }

    #[Test]
    public function processing_log_video_file_path_must_be_trimmed_in_validation(): void
    {
        $invalidPaths = [' ', ' Leading', 'Trailing '];

        foreach ($invalidPaths as $path) {
            $validator = Validator::make(
                ['video_file_path' => $path],
                MediaProcessingLog::validationRules()
            );

            $this->assertTrue($validator->fails(), "Validation should have failed for log path: '{$path}'");
        }
    }

    #[Test]
    public function song_video_video_file_path_must_be_trimmed_in_validation(): void
    {
        $invalidPaths = [' ', ' Leading', 'Trailing '];

        foreach ($invalidPaths as $path) {
            $validator = Validator::make(
                ['video_file_path' => $path],
                SongVideo::validationRules()
            );

            $this->assertTrue($validator->fails(), "Validation should have failed for song video path: '{$path}'");
        }
    }

    #[Test]
    public function sermon_database_rejects_untrimmed_video_path(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sermons_video_file_path_format_check');

        DB::table('sermons')->insert([
            'date' => now(),
            'title' => 'Test',
            'slug' => 'test-sermon',
            'preacher' => 'Test Preacher',
            'video_file_path' => ' untrimmed ',
        ]);
    }

    #[Test]
    public function log_database_rejects_untrimmed_video_path(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('media_processing_logs_video_file_path_format_check');

        DB::table('media_processing_logs')->insert([
            'processing_id' => Str::uuid(),
            'processing_type' => 'video',
            'status' => 'pending',
            'original_filename' => 'test.mp4',
            'video_file_path' => ' untrimmed ',
        ]);
    }

    #[Test]
    public function song_video_database_rejects_untrimmed_video_path(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('song_videos_video_file_path_format_check');

        DB::table('song_videos')->insert([
            'song_id' => Song::factory()->create()->id,
            'video_file_path' => ' untrimmed ',
        ]);
    }

    #[Test]
    public function sermon_attribute_setter_trims_video_path(): void
    {
        $sermon = new Sermon;
        $sermon->video_file_path = '  trimmed/path.mp4  ';
        $this->assertSame('trimmed/path.mp4', $sermon->video_file_path);

        $sermon->video_file_path = '   ';
        $this->assertNull($sermon->video_file_path);
    }

    #[Test]
    public function log_attribute_setter_trims_video_path(): void
    {
        $log = new MediaProcessingLog;
        $log->video_file_path = '  trimmed/path.mp4  ';
        $this->assertSame('trimmed/path.mp4', $log->video_file_path);

        $log->video_file_path = '   ';
        $this->assertNull($log->video_file_path);
    }

    #[Test]
    public function song_video_attribute_setter_trims_video_path(): void
    {
        $songVideo = new SongVideo;
        $songVideo->video_file_path = '  trimmed/path.mp4  ';
        $this->assertSame('trimmed/path.mp4', $songVideo->video_file_path);
    }
}
