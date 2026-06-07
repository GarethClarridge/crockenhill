<?php

declare(strict_types=1);

namespace Tests\Integration\Data;

use App\Data\SermonCreationOptions;
use App\Enums\PreacherSource;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonCreationOptionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_builds_service_section_options_with_run_identity(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'run-123',
            'original_filename' => 'service.mp4',
        ]);
        $preacher = Preacher::factory()->create(['name' => 'Mary Helper']);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'title' => "Children's Talk",
            'extracted_video_path' => 'sermons/sections/55/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-55.mp3',
            'start_time' => 120.0,
            'end_time' => 480.0,
            'duration' => 360.0,
            'metadata' => [
                'childrens_talk_speaker' => [
                    'reviewed' => [
                        'preacher_id' => $preacher->id,
                        'preacher_name' => $preacher->name,
                        'source' => PreacherSource::Manual->value,
                        'confidence' => null,
                    ],
                ],
            ],
        ]);

        $options = SermonCreationOptions::fromServiceSection(
            $section,
            $processingLog,
            date: '2026-05-10',
            service: SermonService::Morning
        );

        $this->assertSame('sermons/audio/section-55.mp3', $options->audioFilePath);
        $this->assertSame('sermons/sections/55/video.mp4', $options->videoFilePath);
        $this->assertSame(SermonSourceType::Livestream, $options->sourceType);
        $this->assertSame('2026-05-10', $options->date);
        $this->assertSame(SermonService::Morning, $options->service);
        $this->assertSame("Children's Talk", $options->customTitle);
        $this->assertSame(SermonContentType::ChildrensTalk, $options->contentType);
        $this->assertSame($preacher->id, $options->preacherId);
        $this->assertSame($preacher->name, $options->preacher);
        $this->assertSame(PreacherSource::Manual, $options->preacherSource);
        $this->assertSame(120.0, $options->segmentStartTime);
        $this->assertSame(480.0, $options->segmentEndTime);
    }
}
