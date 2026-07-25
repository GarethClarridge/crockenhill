<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditSectionAssetsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config(['media-processing.storage.sermon_disk' => 'public']);
    }

    #[Test]
    public function it_passes_when_every_referenced_candidate_exists(): void
    {
        ServiceSection::factory()->create([
            'extracted_video_path' => 'private/section-publications/1/video.mp4',
            'extracted_audio_path' => 'private/section-publications/1/audio.mp3',
        ]);

        Storage::disk('local')->put('private/section-publications/1/video.mp4', 'asset');
        Storage::disk('local')->put('private/section-publications/1/audio.mp3', 'asset');

        $this->artisan('audit:section-assets')
            ->expectsOutputToContain('Section asset audit is clean')
            ->assertSuccessful();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['kinds']['extracted_video']['present']);
        $this->assertSame(1, $report['kinds']['extracted_audio']['present']);
        $this->assertSame(1, $report['sections']['with_referenced_assets']);
        $this->assertSame(0, $report['sections']['with_missing_assets']);
    }

    #[Test]
    public function it_reports_a_referenced_candidate_whose_file_is_gone_without_printing_the_path(): void
    {
        ServiceSection::factory()->create([
            'extracted_video_path' => 'private/section-publications/7/video.mp4',
            'extracted_audio_path' => 'private/section-publications/7/audio.mp3',
        ]);

        Storage::disk('local')->put('private/section-publications/7/audio.mp3', 'asset');

        $this->artisan('audit:section-assets')
            ->doesntExpectOutputToContain('private/section-publications/7/video.mp4')
            ->expectsOutputToContain('Section asset audit found problems')
            ->assertFailed();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['kinds']['extracted_video']['missing']);
        $this->assertSame(1, $report['kinds']['extracted_video']['private_missing']);
        $this->assertSame(1, $report['kinds']['extracted_audio']['present']);
        $this->assertSame(1, $report['sections']['with_missing_assets']);
        $this->assertArrayNotHasKey('findings', $report);
    }

    #[Test]
    public function it_lists_section_ids_but_never_paths_with_the_details_option(): void
    {
        $section = ServiceSection::factory()->create([
            'extracted_video_path' => 'private/section-publications/9/video.mp4',
        ]);

        $this->artisan('audit:section-assets --details')
            ->doesntExpectOutputToContain('private/section-publications/9/video.mp4')
            ->expectsOutputToContain((string) $section->id)
            ->assertFailed();

        $report = $this->jsonReport('--details');

        $this->assertSame(
            [['section_id' => $section->id, 'kind' => 'extracted_video', 'issue' => 'missing']],
            $report['findings'],
        );
    }

    #[Test]
    public function it_resolves_non_private_candidates_against_the_sermon_disk(): void
    {
        ServiceSection::factory()->create([
            'extracted_video_path' => 'sermons/sections/3/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-3.mp3',
        ]);

        Storage::disk('public')->put('sermons/sections/3/video.mp4', 'asset');
        Storage::disk('public')->put('sermons/audio/section-3.mp3', 'asset');

        $this->artisan('audit:section-assets')->assertSuccessful();

        $report = $this->jsonReport();

        $this->assertSame(2, $report['kinds']['extracted_video']['referenced'] + $report['kinds']['extracted_audio']['referenced']);
        $this->assertSame(0, $report['kinds']['extracted_video']['private_referenced']);
        $this->assertSame(0, $report['kinds']['extracted_audio']['private_referenced']);
    }

    #[Test]
    public function it_partitions_missing_candidates_by_whether_the_source_recording_survives(): void
    {
        $survivingLog = MediaProcessingLog::factory()->livestream()->create([
            'source_file_path' => 'livestream/temp/survivor.mp4',
        ]);
        $wipedLog = MediaProcessingLog::factory()->livestream()->create([
            'source_file_path' => 'livestream/temp/wiped-by-deploy.mp4',
        ]);
        $noSourceLog = MediaProcessingLog::factory()->livestream()->create([
            'source_file_path' => null,
        ]);

        Storage::disk('local')->put('livestream/temp/survivor.mp4', 'source');

        foreach ([$survivingLog, $wipedLog, $noSourceLog] as $log) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $log->id,
                'extracted_video_path' => 'private/section-publications/'.$log->id.'/video.mp4',
            ]);
        }

        $this->artisan('audit:section-assets')->assertFailed();

        $report = $this->jsonReport();

        $this->assertSame(3, $report['sections']['with_missing_assets']);
        $this->assertSame(1, $report['sections']['missing_and_source_media_present']);
        $this->assertSame(1, $report['sections']['missing_and_source_media_gone']);
        $this->assertSame(1, $report['sections']['missing_and_no_source_reference']);
    }

    #[Test]
    public function the_recoverability_buckets_partition_the_missing_total(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'source_file_path' => 'livestream/temp/gone.mp4',
        ]);

        ServiceSection::factory()->count(3)->create([
            'media_processing_log_id' => $log->id,
            'extracted_video_path' => null,
            'extracted_audio_path' => null,
        ])->each(function (ServiceSection $section): void {
            $section->extracted_video_path = 'private/section-publications/'.$section->id.'/video.mp4';
            $section->saveQuietly();
        });

        $this->artisan('audit:section-assets')->assertFailed();

        $report = $this->jsonReport();

        $buckets = $report['sections']['missing_and_source_media_present']
            + $report['sections']['missing_and_source_media_gone']
            + $report['sections']['missing_and_no_source_reference'];

        $this->assertSame($report['sections']['with_missing_assets'], $buckets);
        $this->assertSame(3, $buckets);
    }

    #[Test]
    public function it_ignores_sections_with_no_referenced_candidates(): void
    {
        ServiceSection::factory()->create([
            'extracted_video_path' => null,
            'extracted_audio_path' => null,
        ]);

        $this->artisan('audit:section-assets')->assertSuccessful();

        $report = $this->jsonReport();

        $this->assertSame(0, $report['kinds']['extracted_video']['referenced']);
        $this->assertSame(0, $report['sections']['with_referenced_assets']);
    }

    /**
     * @return array{kinds: array<string, array<string, int>>, sections: array<string, int>, findings?: list<array{section_id: int, kind: string, issue: string}>}
     */
    private function jsonReport(string ...$options): array
    {
        Artisan::call(implode(' ', ['audit:section-assets --json', ...$options]));

        $report = json_decode(Artisan::output(), true);

        $this->assertIsArray($report);

        return $report;
    }
}
