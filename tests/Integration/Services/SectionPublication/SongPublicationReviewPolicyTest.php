<?php

declare(strict_types=1);

namespace Tests\Integration\Services\SectionPublication;

use App\Data\ServiceSectionMetadata;
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

    /**
     * Section 1276 (2026-04-05) holds *Come, behold the wondrous mystery* and
     * *Where, O grave, is your victory?* in one interval. OCR confirmed both and
     * the outer boundary is clean, so nothing else in the policy objected and a
     * 458.71-second clip was generated for the first song alone.
     */
    #[Test]
    public function it_holds_an_interval_ocr_shows_holds_a_second_song(): void
    {
        $section = $this->section(
            'full',
            ['livestream'],
            metadata: [
                'additional_song_matches' => [[
                    'song_id' => $this->otherSongId(),
                    'title' => 'Where, O grave, is your victory?',
                    'confidence' => 0.92,
                    'match_source' => 'ocr',
                ]],
            ],
            start: 1200.0,
            end: 1658.71,
        );

        $assessment = $this->policy->assess($section);

        $this->assertSame(
            ['unresolved_multiple_songs'],
            array_column($assessment['reasons'], 'kind'),
        );
        $this->assertStringContainsString(
            'Where, O grave, is your victory?',
            $assessment['reasons'][0]['detail'],
        );
        $this->assertSame('release_eligible', $assessment['boundary_evidence']['decision']);
    }

    /**
     * Section 3869 (2021-10-10) is the same failure with *Jesus shall take the
     * highest honour* and *Lord, I lift your name on high*, published as a
     * 276.387-second clip. It is kept as a second case because the fix must not
     * depend on one recording's OCR text or duration.
     */
    #[Test]
    public function it_holds_a_second_recording_whose_interval_holds_two_songs(): void
    {
        $section = $this->section(
            'full',
            ['livestream'],
            metadata: [
                'additional_song_matches' => [[
                    'song_id' => $this->otherSongId(),
                    'title' => 'Lord, I lift your name on high',
                    'confidence' => 0.88,
                    'match_source' => 'ocr',
                ]],
            ],
            start: 900.0,
            end: 1176.387,
        );

        $this->assertSame(
            ['unresolved_multiple_songs'],
            array_column($this->policy->reviewReasons($section), 'kind'),
        );
    }

    /**
     * A second OCR frame resolving to the song the section is already assigned
     * is corroboration, not a second performance.
     */
    #[Test]
    public function it_releases_an_interval_whose_further_match_is_the_same_song(): void
    {
        $section = $this->section('full', ['livestream']);

        $section->metadata = ServiceSectionMetadata::fromArray([
            'additional_song_matches' => [[
                'song_id' => $section->churchServiceItem->song_id,
                'title' => 'The same hymn, read from a later frame',
                'confidence' => 0.95,
                'match_source' => 'ocr',
            ]],
        ]);

        $this->assertSame([], $this->policy->reviewReasons($section));
    }

    /**
     * Naming a second song without identifying it is still an unresolved second
     * performance: the missing catalogue id is not evidence of a single song.
     */
    #[Test]
    public function it_holds_an_unidentified_second_song(): void
    {
        $section = $this->section(
            'full',
            ['livestream'],
            metadata: [
                'additional_song_matches' => [[
                    'song_id' => null,
                    'title' => 'A second hymn OCR could not place',
                    'confidence' => 0.4,
                    'match_source' => 'ocr',
                ]],
            ],
        );

        $this->assertSame(
            ['unresolved_multiple_songs'],
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

    /**
     * A gap almost immediately after the candidate start is not an introduction.
     *
     * Reading the thirteen clips the gate held on 4 September showed both false
     * positives at 1.0s and 1.7s of framing — a trailing "Amen" caught at the edge, and
     * a leader's last words running into the first sung line — while the shortest
     * genuine introduction was 11.3s. Holding a clip over one second of speech costs a
     * reviewer more than it protects a listener.
     */
    #[Test]
    public function it_does_not_hold_a_song_when_the_spoken_framing_is_below_the_floor(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $this->storeBoundaryArtifacts(
            $section,
            [
                ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen.'],
                ['start' => 130.0, 'end' => 220.0, 'text' => 'To God be the glory.'],
            ],
            [
                ['time' => 101.0, 'rms' => -20.0],
                ['time' => 115.0, 'rms' => -20.0],
                ['time' => 130.0, 'rms' => -20.0],
            ],
        );

        $assessment = $this->policy->assess($section);

        $this->assertSame([], $assessment['reasons']);
        $this->assertSame('release_eligible', $assessment['boundary_evidence']['decision']);

        $startEvidence = $assessment['boundary_evidence']['start_evidence'];
        $this->assertSame('keep_inclusive', $startEvidence['decision']);
        $this->assertSame('spoken_framing_below_floor', $startEvidence['basis']);
        $this->assertEqualsWithDelta(1.0, $startEvidence['gap_offset_seconds'], 0.001);
        $this->assertSame(100.0, (float) $section->start_time);
        $this->assertSame(300.0, (float) $section->end_time);
    }

    #[Test]
    public function it_still_holds_a_song_when_the_spoken_framing_reaches_the_floor(): void
    {
        config(['media-processing.section_publishing.song_boundary.min_spoken_framing_seconds' => 3]);

        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $this->storeBoundaryArtifacts(
            $section,
            [
                ['start' => 100.0, 'end' => 103.0, 'text' => 'Let us stand and sing.'],
                ['start' => 130.0, 'end' => 220.0, 'text' => 'To God be the glory.'],
            ],
            [
                ['time' => 103.0, 'rms' => -20.0],
                ['time' => 115.0, 'rms' => -20.0],
                ['time' => 130.0, 'rms' => -20.0],
            ],
        );

        $assessment = $this->policy->assess($section);

        $this->assertSame(
            ['song_boundary_spoken_framing'],
            array_column($assessment['reasons'], 'kind'),
        );
        $this->assertSame('review', $assessment['boundary_evidence']['decision']);
        $this->assertSame(
            'timed_transcript_wordless_gap',
            $assessment['boundary_evidence']['start_evidence']['basis'],
        );
    }

    #[Test]
    public function it_can_disable_the_spoken_framing_floor_entirely(): void
    {
        config(['media-processing.section_publishing.song_boundary.min_spoken_framing_seconds' => 0]);

        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $this->storeBoundaryArtifacts(
            $section,
            [
                ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen.'],
                ['start' => 130.0, 'end' => 220.0, 'text' => 'To God be the glory.'],
            ],
            [
                ['time' => 101.0, 'rms' => -20.0],
                ['time' => 115.0, 'rms' => -20.0],
                ['time' => 130.0, 'rms' => -20.0],
            ],
        );

        $assessment = $this->policy->assess($section);

        $this->assertSame(
            ['song_boundary_spoken_framing'],
            array_column($assessment['reasons'], 'kind'),
        );
        $this->assertSame('review', $assessment['boundary_evidence']['decision']);
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

        // A storage-layer failure is named apart from a genuinely absent
        // artifact. Both hold the clip, but only one of them means something is
        // broken, and a whole pass held for the same unreadable disk must be
        // distinguishable from a whole pass held on missing evidence.
        $this->assertSame(
            ['song_boundary_evidence_unreadable'],
            array_column($assessment['reasons'], 'kind'),
        );
        $this->assertSame('unavailable', $assessment['boundary_evidence']['inputs']['service_transcript']['status']);
        $this->assertSame('available', $assessment['boundary_evidence']['inputs']['rms_log']['status']);
        $this->assertTrue($assessment['boundary_evidence']['start_evidence']['storage_error']);
    }

    #[Test]
    public function it_names_genuinely_absent_boundary_evidence_apart_from_a_storage_failure(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $log = $section->processingLog;

        Storage::disk('local')->delete([
            'service-transcripts/test-'.$log->processing_id.'.normalized.json',
            'service-transcripts/test-'.$log->processing_id.'.rms.json',
        ]);
        $log->forceFill(['rms_log_path' => null])->save();

        $assessment = $this->policy->assess($section->fresh());

        $this->assertSame(
            ['song_boundary_evidence_unavailable'],
            array_column($assessment['reasons'], 'kind'),
        );
        $this->assertSame('missing', $assessment['boundary_evidence']['inputs']['service_transcript']['status']);
        $this->assertSame('not_recorded', $assessment['boundary_evidence']['inputs']['rms_log']['status']);
        $this->assertFalse($assessment['boundary_evidence']['start_evidence']['storage_error']);
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
     * The M5 case this exists to catch: roughly 27 seconds of speech after the
     * singing, transcribed as several short cues. Requiring the gap to sit
     * immediately before a single long final cue matched none of it.
     */
    #[Test]
    public function it_holds_a_multi_cue_spoken_tail_after_the_singing(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $this->storeBoundaryArtifacts(
            $section,
            [
                ['start' => 100.0, 'end' => 180.0, 'text' => 'The song begins.'],
                ['start' => 181.0, 'end' => 268.0, 'text' => 'The song continues.'],
                ['start' => 273.0, 'end' => 281.0, 'text' => 'And now may the grace'],
                ['start' => 281.5, 'end' => 290.0, 'text' => 'of our Lord Jesus Christ'],
                ['start' => 290.5, 'end' => 300.0, 'text' => 'be with you all. Amen.'],
            ],
            [
                ['time' => 268.0, 'rms' => -20.0],
                ['time' => 270.0, 'rms' => -20.0],
                ['time' => 273.0, 'rms' => -20.0],
            ],
        );

        $assessment = $this->policy->assess($section);

        $this->assertContains(
            'song_boundary_trailing_content',
            array_column($assessment['reasons'], 'kind'),
        );
        $this->assertEqualsWithDelta(
            27.0,
            $assessment['boundary_evidence']['end_evidence']['trailing_content_seconds'],
            0.01,
        );
    }

    /**
     * A short tail is not evidence of following content, however it is cut up.
     */
    #[Test]
    public function it_releases_a_song_whose_trailing_span_is_below_the_minimum(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $this->storeBoundaryArtifacts(
            $section,
            [
                ['start' => 100.0, 'end' => 180.0, 'text' => 'The song begins.'],
                ['start' => 181.0, 'end' => 294.0, 'text' => 'The song continues to its final line.'],
                ['start' => 297.0, 'end' => 300.0, 'text' => 'Amen.'],
            ],
            [
                ['time' => 294.0, 'rms' => -20.0],
                ['time' => 295.0, 'rms' => -20.0],
                ['time' => 297.0, 'rms' => -20.0],
            ],
        );

        $assessment = $this->policy->assess($section);

        $this->assertNotContains(
            'song_boundary_trailing_content',
            array_column($assessment['reasons'], 'kind'),
        );
    }

    /**
     * @param  list<string>  $provenance
     * @param  array<string, mixed>  $metadata
     */
    /** A catalogue song other than the one the section under test is assigned. */
    private function otherSongId(): int
    {
        return Song::factory()->create()->id;
    }

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
