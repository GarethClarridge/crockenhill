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

    /**
     * F44: curated manifest facts describe the service's sermon. A children's
     * talk extracted from the same recording must not inherit them.
     */
    #[Test]
    public function curated_facts_do_not_reach_a_childrens_talk_from_the_same_recording(): void
    {
        $processingLog = $this->historicLogWithCuratedFacts();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'title' => "Children's Talk",
            'extracted_audio_path' => 'sermons/audio/section-70.mp3',
        ]);

        $options = SermonCreationOptions::fromServiceSection(
            $section,
            $processingLog,
            date: '2019-10-06',
            service: SermonService::Morning
        );

        $this->assertNull($options->editorialFacts);
        $this->assertNull($options->curatedFacts());
        $this->assertNull($options->preacher);
    }

    #[Test]
    public function curated_facts_reach_a_sermon_section(): void
    {
        $processingLog = $this->historicLogWithCuratedFacts();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Sermon,
            'title' => 'Sermon',
            'extracted_audio_path' => 'sermons/audio/section-71.mp3',
        ]);

        $options = SermonCreationOptions::fromServiceSection(
            $section,
            $processingLog,
            date: '2019-10-06',
            service: SermonService::Morning
        );

        $this->assertSame('The God Who Provides', $options->curatedFacts()?->title);
        $this->assertSame('Harvest Thanksgiving', $options->curatedFacts()?->occasion);
        $this->assertSame('Rev. Alan Brown', $options->preacher);
        $this->assertSame(PreacherSource::Manual, $options->preacherSource);
    }

    #[Test]
    public function livestream_options_carry_curated_facts_and_speaker(): void
    {
        $processingLog = $this->historicLogWithCuratedFacts();

        $options = SermonCreationOptions::fromLivestream($processingLog, []);

        $this->assertSame('The God Who Provides', $options->curatedFacts()?->title);
        $this->assertSame('Rev. Alan Brown', $options->preacher);
        $this->assertSame(PreacherSource::Manual, $options->preacherSource);
        $this->assertFalse($options->needsPreacherReview);
    }

    #[Test]
    public function an_ordinary_livestream_run_carries_no_curated_facts(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'audio_file_path' => 'sermons/audio/ordinary.mp3',
        ]);

        $options = SermonCreationOptions::fromLivestream($processingLog, []);

        $this->assertNull($options->editorialFacts);
        $this->assertNull($options->preacher);
        $this->assertNull($options->preacherSource);
    }

    private function historicLogWithCuratedFacts(): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create([
            'audio_file_path' => 'sermons/audio/historic.mp3',
            'processing_metadata' => [
                'historic_import' => [
                    'tag' => 'livestream',
                    'editorial_facts' => [
                        'occasion' => 'Harvest Thanksgiving',
                        'title' => 'The God Who Provides',
                        'speaker' => 'Rev. Alan Brown',
                        'scripture_reference' => 'Ruth 2:1-23',
                        'series' => 'Ruth',
                    ],
                ],
            ],
        ]);
    }
}
