<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\VideoDeadPictureCoverage;
use App\Enums\AudioProfile;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\DeadFeedExcision;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\Audio\AudioTreatmentSettings;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Video\VideoDeadPictureProbe;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\MediaProcessingVersion;
use App\Support\PublicationCandidate;
use App\Support\SectionReviewFlagPolicy;
use App\Support\SermonAutoExtractionPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BanksNoWordOutputEdges;
use Tests\TestCase;

class DeadFeedExcisionTest extends TestCase
{
    use BanksNoWordOutputEdges;
    use DatabaseTransactions;

    private MediaProcessingLog $log;

    private ServiceSection $section;

    private string $rms;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $this->log = MediaProcessingLog::factory()->livestream()->create([
            'duration' => 500, 'source_file_path' => 'temp/source.mp4', 'rms_log_path' => 'temp/e20.rms',
        ]);
        $this->section = ServiceSection::factory()->create([
            'media_processing_log_id' => $this->log->id, 'section_type' => ServiceSectionType::ShortTalk,
            'start_time' => 100, 'end_time' => 400, 'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);
        $this->rms = "frame:0 pts:0 pts_time:0\nlavfi.astats.Overall.RMS_level=-20\n"
            ."frame:1 pts:0 pts_time:200\nlavfi.astats.Overall.RMS_level=-80\n"
            ."frame:2 pts:0 pts_time:240\nlavfi.astats.Overall.RMS_level=-20\n";
        Storage::disk('local')->put('temp/e20.rms', $this->rms);
    }

    #[Test]
    public function a_short_talk_uses_the_same_excision_and_keeps_the_source_loss_review_note(): void
    {
        $this->bank([[200, 240]]);
        $this->bankNoWordOutputEdges($this->log);
        $plan = app(CueSafeExtractionPlan::class)->forSection($this->section->fresh());
        $this->assertSame([['start_time' => 100.0, 'end_time' => 200.0], ['start_time' => 240.0, 'end_time' => 400.0]], $plan['segments']);
        $this->assertSame('E20', $plan['dead_feed_excisions'][0]['ruling']);
        $flags = [ServiceStructureValidator::FLAG_TALK_AUDIO_DROPOUT];
        $this->assertTrue(SectionReviewFlagPolicy::requiresManualReview(ServiceSectionType::ShortTalk, $flags));
        $this->assertTrue(SermonAutoExtractionPolicy::reviewStatePermitsAutoExtraction(true, $flags));
    }

    #[Test]
    #[TestWith([true])]
    #[TestWith([false])]
    public function a_short_talk_candidate_requires_repreparation_only_with_a_corroborated_dead_feed(bool $deadPicture): void
    {
        $this->bank($deadPicture ? [[200, 240]] : []);
        $this->section->update(['metadata' => ['publication_candidate_extraction' => [
            'media_processing' => MediaProcessingVersion::signature(),
            'audio_treatment' => ['settings' => AudioTreatmentSettings::for(AudioProfile::Speech)->toArray()],
            'segments' => [['start_time' => 100.0, 'end_time' => 400.0]],
        ]]]);
        $this->assertSame($deadPicture ? 'still contains an E20 dead feed' : null, PublicationCandidate::staleReason($this->section->fresh(), AudioProfile::Speech));
    }

    /** @return iterable<string, array{string}> */
    public static function unchangedCases(): iterable
    {
        foreach (['sound_only', 'picture_only', 'short_intersection', 'edge', 'song', 'no_video', 'unreadable', 'missing', 'corrupt', 'malformed', 'stale_rms', 'stale_source'] as $case) {
            yield $case => [$case];
        }
    }

    #[Test]
    #[DataProvider('unchangedCases')]
    public function e20_leaves_other_cases_unchanged(string $case): void
    {
        $intervals = match ($case) {
            'sound_only' => [],
            'short_intersection' => [[230, 270]],
            'unreadable' => null,
            default => [[200, 240]],
        };
        $this->bank($intervals);
        if ($case === 'picture_only') {
            $this->rms = str_replace('=-80', '=-20', $this->rms);
            Storage::disk('local')->put('temp/e20.rms', $this->rms);
            $this->bank($intervals);
        }
        if ($case === 'edge') {
            $this->section->update(['start_time' => 200, 'duration' => 200]);
        }
        if ($case === 'song') {
            $this->section->update(['section_type' => ServiceSectionType::Song]);
        }
        if ($case === 'no_video') {
            $this->log->update(['source_file_path' => null]);
            $this->bank($intervals);
        }
        if ($case === 'missing') {
            $entry = ServiceArtifactStorage::recordedFor($this->log->fresh())[0];
            Storage::disk($entry['disk'])->delete($entry['path']);
        }
        if (in_array($case, ['corrupt', 'malformed'], true)) {
            $entry = ServiceArtifactStorage::recordedFor($this->log->fresh())[0];
            Storage::disk($entry['disk'])->put($entry['path'], $case === 'corrupt' ? '{broken' : json_encode([
                'source' => app(DeadFeedExcision::class)->sourceIdentity($this->log),
                'rms_sha256' => hash('sha256', $this->rms), 'windows' => 'unreadable',
            ], JSON_THROW_ON_ERROR));
        }
        if ($case === 'stale_rms') {
            Storage::disk('local')->append('temp/e20.rms', 'changed');
        }
        if ($case === 'stale_source') {
            $this->log->update(['source_file_path' => 'temp/replacement.mp4']);
        }
        $spans = [['start_time' => (float) $this->section->start_time, 'end_time' => 400.0]];
        $result = app(DeadFeedExcision::class)->apply($this->log->fresh(), [$this->section->fresh()], $spans);
        $this->assertSame($spans, $result['segments']);
        $this->assertSame([], $result['dead_feed_excisions']);
    }

    #[Test]
    public function an_edge_already_snapped_out_of_dead_feed_is_not_excised(): void
    {
        $this->bank([[200, 240]]);
        $spans = [['start_time' => 240.3, 'end_time' => 400.0]];
        $this->assertSame($spans, app(DeadFeedExcision::class)->apply($this->log->fresh(), [$this->section], $spans)['segments']);
    }

    #[Test]
    public function plan_resolution_performs_no_database_writes_and_binds_evidence_identity(): void
    {
        $this->bank([[200, 240]]);
        $service = app(DeadFeedExcision::class);
        $first = $service->identity($this->log->fresh());
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $service->apply($this->log->fresh(), [$this->section], [['start_time' => 100.0, 'end_time' => 400.0]]);
        $this->assertSame([], $writes);
        $this->bank([[205, 235]]);
        $this->assertNotSame($first, $service->identity($this->log->fresh()));
    }

    #[Test]
    public function transcript_slicing_omits_the_excised_interval_using_existing_multi_span_semantics(): void
    {
        $this->bank([[200, 240]]);
        $result = app(DeadFeedExcision::class)->apply($this->log->fresh(), [$this->section], [['start_time' => 100.0, 'end_time' => 400.0]]);
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 180.0, 'end' => 195.0, 'text' => 'Before.'],
            ['start' => 210.0, 'end' => 230.0, 'text' => 'Dead feed text.'],
            ['start' => 245.0, 'end' => 260.0, 'text' => 'After.'],
        ], 500, ChurchServiceTranscript::SOURCE_MOCK);
        $spans = array_map(static fn (array $span): array => ['start' => $span['start_time'], 'end' => $span['end_time']], $result['segments']);
        $this->assertSame('Before. After.', $transcript->sliceTextForSpans($spans));
    }

    #[Test]
    public function failed_source_probe_is_banked_as_no_evidence_and_reused_without_reprobing(): void
    {
        Storage::disk('local')->put('temp/source.mp4', 'source');
        $this->mock(VideoDeadPictureProbe::class)->shouldReceive('probeWindow')->once()->withArgs(
            static fn (string $path, float $start, float $end): bool => str_ends_with($path, 'temp/source.mp4') && $start === 180.0 && $end === 260.0,
        )->andReturnNull();
        $service = app(DeadFeedExcision::class);
        $service->prepare($this->log, app(StorageAdapterHelper::class));
        $service->prepare($this->log, app(StorageAdapterHelper::class));
        $this->assertCount(1, ServiceArtifactStorage::recordedFor($this->log->fresh()));
        $this->assertSame([], $service->apply($this->log, [$this->section], [['start_time' => 100.0, 'end_time' => 400.0]])['dead_feed_excisions']);
    }

    #[Test]
    public function successful_source_evidence_is_banked_beside_the_other_service_artifacts(): void
    {
        Storage::disk('local')->put('temp/source.mp4', 'source');
        $coverage = new VideoDeadPictureCoverage(80.0, 40.0, 40.0, 0.0, [[200.0, 240.0]]);
        $this->mock(VideoDeadPictureProbe::class)->shouldReceive('probeWindow')->once()->andReturn($coverage);
        $service = app(DeadFeedExcision::class);
        $service->prepare($this->log, app(StorageAdapterHelper::class));
        $entry = ServiceArtifactStorage::recordedFor($this->log->fresh())[0];
        $this->assertSame('dead-picture', $entry['kind']);
        $this->assertStringEndsWith('.dead-picture.json', $entry['path']);
        $banked = json_decode(Storage::disk($entry['disk'])->get($entry['path']), true, flags: JSON_THROW_ON_ERROR);
        $this->assertEquals([[200.0, 240.0]], $banked['audio_candidates']);
        $this->assertCount(1, $service->apply($this->log, [$this->section], [['start_time' => 100.0, 'end_time' => 400.0]])['dead_feed_excisions']);
    }

    #[Test]
    public function newly_identified_talks_cannot_reuse_an_incomplete_set_of_source_windows(): void
    {
        $this->section->update(['end_time' => 280, 'duration' => 180]);
        Storage::disk('local')->put('temp/source.mp4', 'source');
        Storage::disk('local')->append('temp/e20.rms', "frame:3 pts:0 pts_time:300\nlavfi.astats.Overall.RMS_level=-inf\n"
            ."frame:4 pts:0 pts_time:330\nlavfi.astats.Overall.RMS_level=-20\n");
        $this->mock(VideoDeadPictureProbe::class)->shouldReceive('probeWindow')->times(3)->andReturnNull();
        $service = app(DeadFeedExcision::class);
        $service->prepare($this->log, app(StorageAdapterHelper::class));
        ServiceSection::factory()->create([
            'media_processing_log_id' => $this->log->id, 'section_type' => ServiceSectionType::ShortTalk,
            'start_time' => 290, 'end_time' => 400,
        ]);
        $service->prepare($this->log, app(StorageAdapterHelper::class));
        $entry = ServiceArtifactStorage::recordedFor($this->log->fresh())[0];
        $banked = json_decode(Storage::disk($entry['disk'])->get($entry['path']), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $banked['audio_candidates']);
        $this->assertCount(2, $banked['windows']);
    }

    #[Test]
    public function collection_does_not_probe_dead_audio_outside_talks(): void
    {
        $this->section->update(['section_type' => ServiceSectionType::Song]);
        Storage::disk('local')->put('temp/source.mp4', 'source');
        $this->mock(VideoDeadPictureProbe::class)->shouldNotReceive('probeWindow');
        app(DeadFeedExcision::class)->prepare($this->log, app(StorageAdapterHelper::class));
        $this->assertSame([], ServiceArtifactStorage::recordedFor($this->log->fresh()));
    }

    /** @param list<array{int, int}>|null $intervals */
    private function bank(?array $intervals): void
    {
        app(ServiceArtifactStorage::class)->putJson($this->log->processing_id, DeadFeedExcision::ARTIFACT_KIND, [
            'source' => app(DeadFeedExcision::class)->sourceIdentity($this->log), 'rms_sha256' => hash('sha256', $this->rms),
            'windows' => [['start' => 180.0, 'end' => 280.0, 'dead_intervals' => $intervals]],
        ]);
    }
}
