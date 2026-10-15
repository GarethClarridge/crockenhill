<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonPublicationState;
use App\Enums\TalkType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HoldRunOutputOnHoldingDiskCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private MediaProcessingLog $run;

    private Sermon $sermon;

    private SongVideo $clip;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_staging');
        config()->set('media-processing.storage.sermon_disk', 'historic_staging');
        config()->set('media-processing.storage.transcript_disk', 'historic_staging');

        $this->run = MediaProcessingLog::factory()->livestream()->create();
        $this->sermon = Sermon::factory()->create([
            'livestream_processing_id' => $this->run->processing_id,
            'content_type' => TalkType::Sermon,
            'audio_file_path' => 'sermons/audio/weekly_sermon.mp3',
            'video_file_path' => 'sermons/1310/video.mp4',
            'transcript_file_path' => 'transcripts/sermon_1310.md',
            'thumbnail_file_path' => null,
            'thumbnail_metadata' => null,
            'publication_state' => SermonPublicationState::Published,
            'asset_disk' => null,
        ]);
        $section = ServiceSection::factory()->create(['media_processing_log_id' => $this->run->id]);
        $this->clip = SongVideo::factory()->create([
            'service_section_id' => $section->id,
            'video_file_path' => 'sermons/songs/519/'.$section->id.'.mp4',
            'publication_state' => SermonPublicationState::Published,
            'asset_disk' => null,
        ]);

        foreach (['sermons/audio/weekly_sermon.mp3', 'sermons/1310/video.mp4', 'transcripts/sermon_1310.md', $this->clip->video_file_path] as $path) {
            Storage::disk('historic_staging')->put($path, 'bytes');
        }
    }

    #[Test]
    public function a_dry_run_names_the_output_and_holds_nothing(): void
    {
        $this->artisan('historic-import:hold-run-output', ['--processing-id' => $this->run->processing_id])
            ->expectsOutputToContain('Sermons:     '.$this->sermon->id)
            ->expectsOutputToContain('Song videos: '.$this->clip->id)
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(SermonPublicationState::Published, $this->sermon->fresh()?->publication_state);
        $this->assertNull($this->clip->fresh()?->asset_disk);
    }

    #[Test]
    public function apply_quarantines_the_run_output_on_the_holding_disk(): void
    {
        $this->artisan('historic-import:hold-run-output', ['--processing-id' => $this->run->processing_id, '--apply' => true, '--yes' => true])
            ->assertSuccessful();

        foreach ([$this->sermon->fresh(), $this->clip->fresh()] as $record) {
            $this->assertSame(SermonPublicationState::Quarantined, $record?->publication_state);
            $this->assertSame('historic_staging', $record?->asset_disk);
            $this->assertNull($record?->historic_import_operation_id);
        }

        $this->artisan('historic-import:hold-run-output', ['--processing-id' => $this->run->processing_id])
            ->expectsOutputToContain('Nothing this run published is waiting to be held.')
            ->assertSuccessful();
    }

    #[Test]
    public function apply_requires_confirmation(): void
    {
        $this->artisan('historic-import:hold-run-output', ['--processing-id' => $this->run->processing_id, '--apply' => true])
            ->expectsOutputToContain('--apply requires --yes')
            ->assertFailed();

        $this->assertSame(SermonPublicationState::Published, $this->sermon->fresh()?->publication_state);
    }

    #[Test]
    public function a_file_missing_from_the_holding_disk_holds_nothing(): void
    {
        Storage::disk('historic_staging')->delete('transcripts/sermon_1310.md');

        $this->artisan('historic-import:hold-run-output', ['--processing-id' => $this->run->processing_id, '--apply' => true, '--yes' => true])
            ->expectsOutputToContain('sermon '.$this->sermon->id.' transcripts/sermon_1310.md')
            ->assertFailed();

        $this->assertSame(SermonPublicationState::Published, $this->sermon->fresh()?->publication_state);
        $this->assertSame(SermonPublicationState::Published, $this->clip->fresh()?->publication_state);
    }

    #[Test]
    public function a_sermon_disk_that_publishes_holds_nothing(): void
    {
        config()->set('media-processing.storage.sermon_disk', 'public');

        $this->artisan('historic-import:hold-run-output', ['--processing-id' => $this->run->processing_id, '--apply' => true, '--yes' => true])
            ->expectsOutputToContain('publishes; nothing written to it is held')
            ->assertFailed();

        $this->assertSame(SermonPublicationState::Published, $this->sermon->fresh()?->publication_state);
    }

    #[Test]
    public function a_historic_run_is_left_to_its_own_custody(): void
    {
        $this->run->forceFill(['historic_import_operation_id' => $this->createHistoricImportOperation()->id])->save();

        $this->artisan('historic-import:hold-run-output', ['--processing-id' => $this->run->processing_id])
            ->expectsOutputToContain('belongs to a historic operation')
            ->assertFailed();
    }

    #[Test]
    public function another_runs_output_is_never_touched(): void
    {
        $other = MediaProcessingLog::factory()->livestream()->create();
        $otherClip = SongVideo::factory()->create([
            'service_section_id' => ServiceSection::factory()->create(['media_processing_log_id' => $other->id])->id,
            'publication_state' => SermonPublicationState::Published,
            'asset_disk' => null,
        ]);

        $this->artisan('historic-import:hold-run-output', ['--processing-id' => $this->run->processing_id, '--apply' => true, '--yes' => true])
            ->assertSuccessful();

        $this->assertSame(SermonPublicationState::Published, $otherClip->fresh()?->publication_state);
    }
}
