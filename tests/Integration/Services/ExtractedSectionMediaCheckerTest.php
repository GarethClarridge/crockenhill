<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ExtractedSectionMediaChecker;
use App\Services\ChurchService\SectionPublication\SongPublicationHandler;
use App\Services\ChurchService\SectionPublication\TalkPublicationHandler;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExtractedSectionMediaCheckerTest extends TestCase
{
    use RefreshDatabase;

    private ExtractedSectionMediaChecker $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ExtractedSectionMediaChecker;

        Storage::fake('public');
        Storage::fake('local');
        Config::set('media-processing.storage.sermon_disk', 'public');
    }

    #[Test]
    public function all_reuse_checks_resolve_the_recorded_context_and_preserve_an_active_one(): void
    {
        config(['filesystems.disks.wp74_checker' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/wp74_checker')]]);
        Storage::fake('wp74_checker');
        config(['filesystems.disks.wp74_checker.root' => Storage::disk('wp74_checker')->path('')]);
        config([
            'media-processing.storage.historic_staging_disk' => 'wp74_checker',
            'media-processing.storage.sermon_disk' => 'wp74_checker',
            'media-processing.storage.transcript_disk' => 'wp74_checker',
        ]);
        HistoricStagingGuard::resetBaselineForTesting();
        $context = app(HistoricStagingGuard::class)->contextForApprovedPlan(str_repeat('e', 64), str_repeat('f', 64));
        $run = MediaProcessingLog::factory()->livestream()->create([
            'processing_metadata' => ['historic_import' => ['staging_context' => $context->toArray()]],
        ]);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song,
            'asset_disk' => 'wp74_checker',
            'extracted_video_path' => 'section/video.mp4',
            'extracted_audio_path' => 'section/audio.mp3',
        ]);
        Storage::disk('wp74_checker')->put($context->batchRoot.'/section/video.mp4', 'video');
        Storage::disk('wp74_checker')->put($context->batchRoot.'/section/audio.mp3', 'audio');
        $registry = app(HistoricStagingContextRegistry::class);
        $this->assertTrue($this->service->hasExtractedMedia($section));
        $this->assertTrue(app(SongPublicationHandler::class)->hasReusableExtractedMedia($section));
        $registry->within($context, function () use ($registry, $section, $context): void {
            $section->section_type = ServiceSectionType::ShortTalk;
            $this->assertTrue(app(TalkPublicationHandler::class)->hasReusableExtractedMedia($section));
            $this->assertSame($context, $registry->activeContext());
        });
        $this->assertFalse($registry->isActive());
        $this->assertFalse(Storage::disk('wp74_checker')->exists('section/video.mp4'));
    }

    #[Test]
    public function it_returns_false_if_video_path_is_missing(): void
    {
        $section = ServiceSection::factory()->create([
            'extracted_video_path' => null,
            'extracted_audio_path' => 'audio.mp3',
        ]);

        $this->assertFalse($this->service->hasExtractedMedia($section));

        $section->update(['extracted_video_path' => '']);
        $this->assertFalse($this->service->hasExtractedMedia($section));
    }

    /** A song is published from its video alone; songs are never cut to audio (1363 §4534, 10-22). */
    #[Test]
    public function a_song_with_its_video_has_its_media(): void
    {
        Storage::disk('public')->put('section-publications/1/video.mp4', 'video');
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Song->value,
            'extracted_video_path' => 'section-publications/1/video.mp4',
            'extracted_audio_path' => null,
            'asset_disk' => 'public',
        ]);

        $this->assertTrue($this->service->hasExtractedMedia($section));

        Storage::disk('public')->delete('section-publications/1/video.mp4');
        $this->assertFalse($this->service->hasExtractedMedia($section), 'still needs the video itself');
    }

    #[Test]
    public function it_returns_false_if_audio_path_is_missing(): void
    {
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Sermon->value,
            'extracted_video_path' => 'video.mp4',
            'extracted_audio_path' => null,
        ]);

        $this->assertFalse($this->service->hasExtractedMedia($section));

        $section->update(['extracted_audio_path' => '']);
        $this->assertFalse($this->service->hasExtractedMedia($section));
    }

    #[Test]
    public function it_returns_false_if_files_do_not_exist_on_disk(): void
    {
        $section = ServiceSection::factory()->create([
            'extracted_video_path' => 'video.mp4',
            'extracted_audio_path' => 'audio.mp3',
        ]);

        // No files created in Storage::fake('public')
        $this->assertFalse($this->service->hasExtractedMedia($section));
    }

    #[Test]
    public function it_returns_false_if_only_one_file_exists(): void
    {
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Sermon->value,
            'extracted_video_path' => 'video.mp4',
            'extracted_audio_path' => 'audio.mp3',
        ]);

        Storage::disk('public')->put('video.mp4', 'content');

        $this->assertFalse($this->service->hasExtractedMedia($section));
    }

    #[Test]
    public function it_returns_true_if_both_files_exist_on_disk(): void
    {
        $section = ServiceSection::factory()->create([
            'extracted_video_path' => 'video.mp4',
            'extracted_audio_path' => 'audio.mp3',
        ]);

        Storage::disk('public')->put('video.mp4', 'video content');
        Storage::disk('public')->put('audio.mp3', 'audio content');

        $this->assertTrue($this->service->hasExtractedMedia($section));
    }

    /**
     * Legacy rows still naming a `private/` path are audited against the sermon
     * disk like everything else. The local private directory is gone, so they
     * correctly present as needing re-extraction rather than being looked up on
     * a disk production no longer writes to.
     */
    #[Test]
    public function it_reports_legacy_private_paths_as_missing(): void
    {
        $section = ServiceSection::factory()->create([
            'extracted_video_path' => 'private/sections/video.mp4',
            'extracted_audio_path' => 'private/sections/audio.mp3',
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('private/sections/video.mp4', 'video content');
        Storage::disk('local')->put('private/sections/audio.mp3', 'audio content');

        $this->assertFalse($this->service->hasExtractedMedia($section));
    }
}
