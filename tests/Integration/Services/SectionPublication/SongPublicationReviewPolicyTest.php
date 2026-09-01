<?php

declare(strict_types=1);

namespace Tests\Integration\Services\SectionPublication;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\SectionPublication\SongPublicationReviewPolicy;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongPublicationReviewPolicyTest extends TestCase
{
    use RefreshDatabase;

    private SongPublicationReviewPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.transcript_disk' => 'local',
        ]);

        $this->policy = app(SongPublicationReviewPolicy::class);
    }

    /**
     * A recording graded `short_partial` or `fragmented` does not hold enough of
     * the service to assert on its own which songs were sung, so its clips reach
     * a reviewer unless another source says the same thing.
     */
    #[Test]
    public function it_holds_a_clip_from_an_uncorroborated_partial_recording(): void
    {
        $section = $this->section('short_partial', ['livestream']);

        $this->assertSame(
            ['uncorroborated_partial_recording'],
            array_column($this->policy->reviewReasons($section), 'kind'),
        );
    }

    #[Test]
    public function it_releases_a_partial_recording_another_source_corroborates(): void
    {
        $section = $this->section('short_partial', ['livestream', 'openlp']);

        $this->assertSame([], $this->policy->reviewReasons($section));
    }

    #[Test]
    public function it_releases_a_whole_recording_no_other_source_saw(): void
    {
        $section = $this->section('full', ['livestream']);

        $this->assertSame([], $this->policy->reviewReasons($section));
    }

    #[Test]
    public function it_holds_an_inferred_match_even_when_confidence_is_high_and_the_marker_disagrees(): void
    {
        $section = $this->section(
            'full',
            ['livestream'],
            ServiceSectionSongMatchType::Inferred,
            [
                'review_flags' => [ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH],
                'transcript_song_match' => [
                    'confidence' => 1.0,
                    'match_source' => 'title_hint_first_line',
                ],
            ],
        );

        $reasons = $this->policy->reviewReasons($section);

        $this->assertSame(['inferred_song_match'], array_column($reasons, 'kind'));
        $this->assertStringContainsString('inferred', $reasons[0]['detail']);
    }

    #[Test]
    public function it_holds_an_inferred_match_without_confidence_metadata(): void
    {
        $section = $this->section('full', ['livestream'], ServiceSectionSongMatchType::Inferred);

        $this->assertSame(
            ['inferred_song_match'],
            array_column($this->policy->reviewReasons($section), 'kind'),
        );
    }

    #[Test]
    public function it_records_an_inclusive_clean_boundary_without_creating_a_boundary_hold(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $this->storeBoundaryArtifacts(
            $section,
            [
                ['start' => 100.0, 'end' => 150.0, 'text' => 'Please stand and sing.'],
                ['start' => 150.0, 'end' => 240.0, 'text' => 'We will sing together.'],
            ],
            [
                ['time' => 100.0, 'rms' => -20.0],
                ['time' => 150.0, 'rms' => -20.0],
                ['time' => 240.0, 'rms' => -20.0],
            ],
        );

        $assessment = $this->policy->assess($section);

        $this->assertSame([], $assessment['reasons']);
        $this->assertSame('release_eligible', $assessment['boundary_evidence']['decision']);
        $this->assertSame('inclusive', $assessment['boundary_evidence']['candidate']['kind']);
        $this->assertSame('retain_inclusive_candidate', $assessment['boundary_evidence']['action']);
        $this->assertSame('keep_inclusive', $assessment['boundary_evidence']['start_evidence']['decision']);
        $this->assertSame('keep_inclusive', $assessment['boundary_evidence']['end_evidence']['decision']);
        $this->assertSame('available', $assessment['boundary_evidence']['inputs']['service_transcript']['status']);
        $this->assertSame('available', $assessment['boundary_evidence']['inputs']['rms_log']['status']);
        $this->assertSame(100.0, (float) $section->start_time);
        $this->assertSame(300.0, (float) $section->end_time);
    }

    #[Test]
    public function it_holds_a_song_when_transcript_and_rms_corroborate_spoken_framing(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $this->storeBoundaryArtifacts(
            $section,
            [
                ['start' => 100.0, 'end' => 112.0, 'text' => 'Please stand as we sing.'],
                ['start' => 120.0, 'end' => 220.0, 'text' => 'We will sing now.'],
            ],
            [
                ['time' => 100.0, 'rms' => -20.0],
                ['time' => 112.0, 'rms' => -20.0],
                ['time' => 116.0, 'rms' => -20.0],
                ['time' => 120.0, 'rms' => -20.0],
            ],
        );

        $assessment = $this->policy->assess($section);

        $this->assertSame(
            ['song_boundary_spoken_framing'],
            array_column($assessment['reasons'], 'kind'),
        );
        $this->assertSame('review', $assessment['boundary_evidence']['decision']);
        $this->assertSame('review', $assessment['boundary_evidence']['start_evidence']['decision']);
        $this->assertSame('timed_transcript_wordless_gap', $assessment['boundary_evidence']['start_evidence']['basis']);
        $this->assertSame(100.0, (float) $section->start_time);
        $this->assertSame(300.0, (float) $section->end_time);
    }

    #[Test]
    public function it_routes_a_wordless_gap_beyond_the_configured_limit_without_recutting(): void
    {
        config(['media-processing.section_publishing.song_boundary.max_spoken_framing_seconds' => 5]);

        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $this->storeBoundaryArtifacts(
            $section,
            [
                ['start' => 100.0, 'end' => 112.0, 'text' => 'Please stand as we sing.'],
                ['start' => 120.0, 'end' => 220.0, 'text' => 'We will sing now.'],
            ],
            [
                ['time' => 112.0, 'rms' => -20.0],
                ['time' => 116.0, 'rms' => -20.0],
                ['time' => 120.0, 'rms' => -20.0],
            ],
        );

        $assessment = $this->policy->assess($section);

        $this->assertSame(
            ['song_boundary_spoken_framing_exceeds_limit'],
            array_column($assessment['reasons'], 'kind'),
        );
        $this->assertSame('retain_inclusive_candidate', $assessment['boundary_evidence']['action']);
        $this->assertSame(100.0, (float) $section->start_time);
        $this->assertSame(300.0, (float) $section->end_time);
    }

    #[Test]
    public function it_holds_a_transcript_gap_when_rms_evidence_is_missing(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $log = $section->processingLog;
        $transcriptPath = 'service-transcripts/test-'.$log->processing_id.'.normalized.json';

        Storage::disk('local')->put($transcriptPath, json_encode([
            'cues' => [
                ['start' => 100.0, 'end' => 112.0, 'text' => 'Please stand as we sing.'],
                ['start' => 120.0, 'end' => 220.0, 'text' => 'We will sing now.'],
            ],
        ], JSON_THROW_ON_ERROR));
        $log->forceFill(['rms_log_path' => null])->save();
        $log->putServiceTranscriptPath($transcriptPath);

        $assessment = $this->policy->assess($section->fresh());

        $this->assertSame(
            ['song_boundary_evidence_unavailable'],
            array_column($assessment['reasons'], 'kind'),
        );
        $this->assertSame('review', $assessment['boundary_evidence']['decision']);
        $this->assertSame('review', $assessment['boundary_evidence']['start_evidence']['decision']);
        $this->assertSame('boundary_evidence_unavailable', $assessment['boundary_evidence']['start_evidence']['basis']);
        $this->assertSame('not_recorded', $assessment['boundary_evidence']['inputs']['rms_log']['status']);
    }

    #[Test]
    public function it_holds_a_song_when_the_transcript_artifact_is_corrupt(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $log = $section->processingLog;
        $transcriptPath = 'service-transcripts/test-'.$log->processing_id.'.normalized.json';

        Storage::disk('local')->put($transcriptPath, '{not-json');
        $log->putServiceTranscriptPath($transcriptPath);

        $assessment = $this->policy->assess($section->fresh());

        $this->assertSame(
            ['song_boundary_evidence_unavailable'],
            array_column($assessment['reasons'], 'kind'),
        );
        $this->assertSame('unavailable', $assessment['boundary_evidence']['inputs']['service_transcript']['status']);
        $this->assertSame('available', $assessment['boundary_evidence']['inputs']['rms_log']['status']);
    }

    #[Test]
    public function it_holds_a_final_audio_backed_timed_tail_as_following_content(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $this->storeBoundaryArtifacts(
            $section,
            [
                ['start' => 100.0, 'end' => 180.0, 'text' => 'The song begins.'],
                ['start' => 181.0, 'end' => 245.0, 'text' => 'The song continues.'],
                ['start' => 260.0, 'end' => 300.0, 'text' => 'The grace of our Lord be with you.'],
            ],
            [
                ['time' => 245.0, 'rms' => -20.0],
                ['time' => 252.0, 'rms' => -20.0],
                ['time' => 260.0, 'rms' => -20.0],
            ],
        );

        $assessment = $this->policy->assess($section);

        $this->assertContains(
            'song_boundary_trailing_content',
            array_column($assessment['reasons'], 'kind'),
        );
        $this->assertSame('review', $assessment['boundary_evidence']['end_evidence']['decision']);
        $this->assertSame(
            'timed_transcript_wordless_gap_before_final_cue',
            $assessment['boundary_evidence']['end_evidence']['basis'],
        );
    }

    /**
     * @param  list<string>  $provenance
     * @param  array<string, mixed>  $metadata
     */
    private function section(
        string $grade,
        array $provenance,
        ServiceSectionSongMatchType $matchType = ServiceSectionSongMatchType::Confirmed,
        array $metadata = [],
        float $start = 600.0,
        float $end = 840.0,
    ): ServiceSection {
        $churchService = ChurchService::factory()->create(['date' => '2020-03-22']);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'song_id' => Song::factory()->create()->id,
            'source' => ChurchServiceItemSource::Livestream,
            'metadata' => [
                'source_evidence' => array_fill_keys($provenance, ['recorded_at' => '2020-03-22T10:00:00Z']),
            ],
        ]);
        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $churchService->id,
            'processing_metadata' => [
                'historic_import' => ['corroboration_grade' => $grade],
            ],
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => $matchType->value,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'metadata' => $metadata,
        ])->fresh();

        // Classification-policy tests need positive boundary evidence unless a
        // test explicitly replaces or removes these artifacts.
        $this->storeBoundaryArtifacts(
            $section,
            [['start' => $start, 'end' => $end, 'text' => 'The song begins.']],
            [['time' => $start, 'rms' => -20.0], ['time' => $end, 'rms' => -20.0]],
        );

        return $section->fresh();
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{time: float, rms: float}>  $samples
     */
    private function storeBoundaryArtifacts(ServiceSection $section, array $cues, array $samples): void
    {
        $log = $section->processingLog;
        $transcriptPath = 'service-transcripts/test-'.$log->processing_id.'.normalized.json';
        $rmsPath = 'service-transcripts/test-'.$log->processing_id.'.rms.json';

        Storage::disk('local')->put($transcriptPath, json_encode([
            'cues' => $cues,
            'duration' => (float) $section->end_time,
            'source' => 'mock',
        ], JSON_THROW_ON_ERROR));
        Storage::disk('local')->put($rmsPath, $this->rmsLog($samples));

        $log->putServiceTranscriptPath($transcriptPath);
        $log->forceFill(['rms_log_path' => $rmsPath])->save();
    }

    /**
     * @param  list<array{time: float, rms: float}>  $samples
     */
    private function rmsLog(array $samples): string
    {
        $lines = [];

        foreach ($samples as $sample) {
            $lines[] = sprintf('pts_time:%.3f', $sample['time']);
            $lines[] = sprintf('lavfi.astats.Overall.RMS_level=%.1f', $sample['rms']);
        }

        return implode("\n", $lines);
    }
}
