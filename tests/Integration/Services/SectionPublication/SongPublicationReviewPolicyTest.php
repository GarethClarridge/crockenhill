<?php

declare(strict_types=1);

namespace Tests\Integration\Services\SectionPublication;

use App\Data\ServiceSectionMetadata;
use App\Data\SuspectTranscriptBlock;
use App\Enums\ChurchServiceItemSource;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeAnswers;
use App\Services\ChurchService\SectionPublication\SongLoopedTranscript;
use App\Services\ChurchService\SectionPublication\SongPublicationBoundaryEvidenceService;
use App\Services\ChurchService\SectionPublication\SongPublicationReviewPolicy;
use App\Services\ChurchService\SectionPublication\SongQuietIntroduction;
use App\Services\ChurchService\SectionPublication\SongSectionWithoutMusic;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SongMusicalIntroduction;
use Database\Factories\SongFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class SongPublicationReviewPolicyTest extends TestCase
{
    use RefreshDatabase;

    private SongPublicationReviewPolicy $policy;

    #[Test]
    public function the_song_duration_floor_is_55_seconds_and_remains_configurable(): void
    {
        $section = $this->section('full', ['livestream']);
        foreach ([[54.9, true], [55.0, false], [60.0, false], [81.0, false]] as [$duration, $held]) {
            $section->duration = (float) $duration;
            $this->assertSame($held, in_array('short_song_clip', array_column($this->policy->reviewReasons($section), 'kind'), true));
        }

        config(['media-processing.section_classification.song_minimum_automatic_duration_seconds' => 70]);
        $section->duration = 60.0;
        $this->assertContains('short_song_clip', array_column($this->policy->reviewReasons($section), 'kind'));
    }

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

    /**
     * Operator ruling 2026-10-10: a spoken introduction may be in the song clip or out of it. In a
     * seeded sample of twelve held clips (24–230 s of opening before the first gap) none held
     * anything that should not be in the clip: five were introductions, two the song itself, and
     * five a short introduction then song the transcript gap fell inside.
     */
    #[Test]
    public function it_keeps_a_song_with_its_spoken_introduction_without_holding_it(): void
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

        $this->assertSame([], $assessment['reasons']);
        $this->assertSame('release_eligible', $assessment['boundary_evidence']['decision']);
        $this->assertSame('keep_inclusive', $assessment['boundary_evidence']['start_evidence']['decision']);
        $this->assertSame('spoken_introduction', $assessment['boundary_evidence']['start_evidence']['basis']);
        $this->assertSame(100.0, (float) $section->start_time);
        $this->assertSame(300.0, (float) $section->end_time);
    }

    /** 1317's opening ran 230 s before its first gap: 13 s of introduction, then the song itself. */
    #[Test]
    public function a_long_opening_before_the_first_gap_is_not_held(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 600.0);
        $this->storeBoundaryArtifacts(
            $section,
            [
                ['start' => 100.0, 'end' => 330.0, 'text' => 'All people that on earth do dwell, sing to the Lord with cheerful voice.'],
                ['start' => 340.0, 'end' => 560.0, 'text' => 'The Lord ye know is God indeed.'],
            ],
            [
                ['time' => 330.0, 'rms' => -20.0],
                ['time' => 335.0, 'rms' => -20.0],
                ['time' => 340.0, 'rms' => -20.0],
            ],
        );

        $assessment = $this->policy->assess($section);

        $this->assertSame([], $assessment['reasons']);
        $this->assertSame('spoken_introduction', $assessment['boundary_evidence']['start_evidence']['basis']);
        $this->assertEqualsWithDelta(230.0, $assessment['boundary_evidence']['start_evidence']['gap_offset_seconds'], 0.001);
        $this->assertSame(600.0, (float) $section->end_time);
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
    public function framing_at_the_floor_is_recorded_as_an_introduction(): void
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

        $this->assertSame([], $assessment['reasons']);
        $this->assertSame('spoken_introduction', $assessment['boundary_evidence']['start_evidence']['basis']);
    }

    #[Test]
    public function without_a_floor_even_an_amen_is_recorded_as_an_introduction(): void
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

        $this->assertSame([], $assessment['reasons']);
        $this->assertSame('spoken_introduction', $assessment['boundary_evidence']['start_evidence']['basis']);
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
     * RA3 R1 (plan §4.1d, 11-09): the hold judges the clip that publishes. 921 and 1986: the planner
     * cut the song off before its spoken tail, but the stored end still held the tail.
     */
    #[Test]
    public function it_releases_a_song_whose_published_cut_ends_before_its_spoken_tail(): void
    {
        $section = $this->spokenTailSection();

        $this->assertContains('song_boundary_trailing_content', array_column($this->policy->assess($section)['reasons'], 'kind'));

        $section = $this->recordCandidate($section, 100.0, 250.0, 'song_end_silence');
        $assessment = $this->policy->assess($section);

        $this->assertNotContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
        $this->assertSame('recorded_candidate', $assessment['boundary_evidence']['published_cut']['source']);
        $this->assertSame(250.0, $assessment['boundary_evidence']['published_cut']['end_time']);
        $this->assertSame(300.0, $assessment['boundary_evidence']['candidate']['end_time']);
    }

    /** The tail is measured to the cut's end, not the stored end. */
    #[Test]
    public function it_still_holds_a_published_cut_that_reaches_its_tail(): void
    {
        $section = $this->recordCandidate($this->spokenTailSection(), 100.0, 295.0, 'song_end_silence');
        $assessment = $this->policy->assess($section);

        $this->assertContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
        $this->assertEqualsWithDelta(35.0, $assessment['boundary_evidence']['end_evidence']['trailing_content_seconds'], 0.01);
        $this->assertSame(295.0, $assessment['boundary_evidence']['published_cut']['end_time']);
    }

    /** 4134: the cut runs past the stored end into speech that the stored bounds never saw. */
    #[Test]
    public function it_holds_a_cut_that_runs_past_the_stored_end_into_speech(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 375.0, end: 595.0);
        $this->storeBoundaryArtifacts($section, [
            ['start' => 375.0, 'end' => 440.0, 'text' => 'The song begins.'],
            ['start' => 441.0, 'end' => 585.0, 'text' => 'The song continues.'],
            ['start' => 596.0, 'end' => 610.0, 'text' => 'Let us pray.'],
        ], [['time' => 585.0, 'rms' => -20.0], ['time' => 590.0, 'rms' => -20.0], ['time' => 596.0, 'rms' => -20.0]], 700.0);

        $this->assertNotContains('song_boundary_trailing_content', array_column($this->policy->assess($section)['reasons'], 'kind'));

        $section = $this->recordCandidate($section, 375.0, 610.1, 'song_start_digital_silence');
        $assessment = $this->policy->assess($section);

        $this->assertContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
        $this->assertEqualsWithDelta(14.1, $assessment['boundary_evidence']['end_evidence']['trailing_content_seconds'], 0.01);
    }

    /**
     * The 5 s cliff is tested against the stored end, the bound the transcript was segmented to, not
     * the cut: judged against a cut 8 s later the last cue (282.5) would look short and release.
     */
    #[Test]
    public function it_judges_reach_against_the_stored_end_not_the_cut(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 285.0);
        $this->storeBoundaryArtifacts($section, [
            ['start' => 100.0, 'end' => 230.0, 'text' => 'The song begins.'],
            ['start' => 236.0, 'end' => 282.5, 'text' => 'The song continues.'],
        ], [['time' => 230.0, 'rms' => -20.0], ['time' => 233.0, 'rms' => -20.0], ['time' => 236.0, 'rms' => -20.0]], 400.0);

        $section = $this->recordCandidate($section, 100.0, 293.0, 'song_end_silence');
        $assessment = $this->policy->assess($section);

        $this->assertContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
        $this->assertEqualsWithDelta(57.0, $assessment['boundary_evidence']['end_evidence']['trailing_content_seconds'], 0.01);
    }

    /**
     * 94692 (class B, named residue with its hold kept). Judged on the cut alone the prayer cue that
     * starts at the stored end makes a new last gap and an 8.3 s tail, so the hold would vanish; the
     * guard keeps the stored-bounds hold for a cut ending on `song_end_voice_track_talking`.
     */
    #[Test]
    public function it_keeps_the_hold_when_a_voice_track_talking_end_leaves_a_short_spoken_tail(): void
    {
        $section = $this->recordCandidate($this->shortSpokenTailSection(), 100.0, 293.3, 'song_end_voice_track_talking');
        $assessment = $this->policy->assess($section);

        $this->assertContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
        $this->assertSame('class_b_voice_track_talking', $assessment['boundary_evidence']['published_cut']['guard']);
    }

    #[Test]
    public function it_releases_the_same_short_tail_for_another_end_reason(): void
    {
        $section = $this->recordCandidate($this->shortSpokenTailSection(), 100.0, 293.3, 'song_end_silence');
        $assessment = $this->policy->assess($section);

        $this->assertNotContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
        $this->assertNull($assessment['boundary_evidence']['published_cut']['guard']);
    }

    #[Test]
    public function it_ignores_a_candidate_cut_that_no_longer_matches_the_section(): void
    {
        $section = $this->recordCandidate($this->spokenTailSection(), 100.0, 250.0, 'song_end_silence', signature: 'stale');
        $assessment = $this->policy->assess($section);

        $this->assertContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
        $this->assertSame('stored_bounds', $assessment['boundary_evidence']['published_cut']['source']);
    }

    #[Test]
    public function the_inputs_fingerprint_changes_when_the_recorded_cut_changes(): void
    {
        $section = $this->spokenTailSection();
        $service = app(SongPublicationBoundaryEvidenceService::class);

        $first = $service->inputsFingerprint($this->recordCandidate($section, 100.0, 250.0, 'song_end_silence'));
        $second = $service->inputsFingerprint($this->recordCandidate($section->fresh(), 100.0, 255.0, 'song_end_silence'));

        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function it_applies_an_operators_end_answer_through_the_recorded_cut(): void
    {
        $section = $this->spokenTailSection();
        $section->metadata = ServiceSectionMetadata::fromArray([...($section->metadata?->toArray() ?? []),
            OutputEdgeAnswers::METADATA_KEY => ['end' => ['time' => 262.0, 'set_by_user_id' => 1, 'set_at' => '2026-10-24T08:24:17+00:00', 'from_time' => 300.0, 'section_span' => ['start_time' => 100.0, 'end_time' => 300.0]]],
        ]);
        $section->save();

        $section = $this->recordCandidate($section->fresh(), 100.0, 262.0, 'operator_edge');
        $assessment = $this->policy->assess($section);

        $this->assertSame(262.0, $assessment['boundary_evidence']['published_cut']['end_time']);
        $this->assertSame(262.0, $assessment['boundary_evidence']['candidate']['end_time']);
    }

    /** WP69: §94692's recorded end/reason; isolate reason staleness by keeping its spans/fade. */
    #[Test]
    public function it_refuses_a_recorded_cut_when_only_the_current_end_reason_differs(): void
    {
        $section = $this->freshnessCase(3741.42, 3929.68, 3876.78);
        $section = $this->recordCandidate($section, 3741.43, 3937.79, 'song_end_fade');
        $section->metadata = ServiceSectionMetadata::fromArray(array_replace_recursive($section->metadata->toArray(), [
            'publication_candidate_extraction' => ['audio_fade_out' => 1.5],
        ]));
        $plan = $section->metadata->raw['publication_candidate_extraction'];
        $plan['cue_edge_widening'][0]['reason'] = 'song_end_voice_track_talking';
        $this->currentPlan($plan);

        $assessment = $this->policy->assess($section);

        $this->assertContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
        $this->assertSame('stored_bounds', $assessment['boundary_evidence']['published_cut']['source']);
        $this->assertSame('stale_plan', $assessment['boundary_evidence']['published_cut']['status']);
        $this->assertSame(3929.68, $assessment['boundary_evidence']['published_cut']['end_time']);
    }

    /** WP69: §1265's stored and current spans, same silence reason. */
    #[Test]
    public function it_refuses_a_recorded_span_beyond_the_extraction_reuse_tolerance(): void
    {
        $section = $this->freshnessCase(1210.0, 1385.82, 1330.04);
        $section = $this->recordCandidate($section, 1210.0, 1394.35, 'song_end_silence');
        $plan = $section->metadata->raw['publication_candidate_extraction'];
        $plan['segments'][0]['end_time'] = 1386.13;
        $this->currentPlan($plan);

        $assessment = $this->policy->assess($section);

        $this->assertContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
        $this->assertSame('stale_plan', $assessment['boundary_evidence']['published_cut']['status']);
        $this->assertSame(1385.82, $assessment['boundary_evidence']['published_cut']['end_time']);
    }

    #[Test]
    public function it_uses_a_recorded_cut_within_the_extraction_reuse_tolerance(): void
    {
        $section = $this->recordCandidate($this->spokenTailSection(), 100.0, 250.0, 'song_end_silence');
        $plan = $section->metadata->raw['publication_candidate_extraction'];
        $plan['segments'][0] = ['start_time' => 100.009, 'end_time' => 250.009];
        $this->currentPlan($plan);

        $assessment = $this->policy->assess($section);

        $this->assertNotContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
        $this->assertSame('used', $assessment['boundary_evidence']['published_cut']['status']);
        $this->assertSame(250.0, $assessment['boundary_evidence']['published_cut']['end_time']);
    }

    #[Test]
    public function it_falls_back_when_banked_planner_evidence_is_unavailable(): void
    {
        $section = $this->recordCandidate($this->spokenTailSection(), 100.0, 250.0, 'song_end_silence');
        $this->mock(CueSafeExtractionPlan::class)->shouldReceive('forSection')->once()
            ->andThrow(new OutputEdgeTimingsMissing('edge_word_timings_missing: regression fixture'));

        $assessment = $this->policy->assess($section);

        $this->assertContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
        $this->assertSame('plan_unavailable', $assessment['boundary_evidence']['published_cut']['status']);
        $this->assertSame('stored_bounds', $assessment['boundary_evidence']['published_cut']['source']);
    }

    #[Test]
    public function it_does_not_silently_swallow_an_unexpected_planner_failure(): void
    {
        $section = $this->recordCandidate($this->spokenTailSection(), 100.0, 250.0, 'song_end_silence');
        $this->mock(CueSafeExtractionPlan::class)->shouldReceive('forSection')->once()
            ->andThrow(new \LogicException('unexpected planner defect'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unexpected planner defect');
        $this->policy->assess($section);
    }

    #[Test]
    public function the_fingerprint_includes_the_recorded_cut_status_even_on_fallback(): void
    {
        $section = $this->recordCandidate($this->spokenTailSection(), 100.0, 250.0, 'song_end_silence', signature: 'stale');
        $service = app(SongPublicationBoundaryEvidenceService::class);
        $signatureMismatch = $service->assess($section);
        $section->metadata = ServiceSectionMetadata::fromArray(array_replace_recursive($section->metadata->toArray(), [
            'publication_candidate_extraction' => ['media_signature' => $section->mediaSignature()],
        ]));
        $this->mock(CueSafeExtractionPlan::class)->shouldReceive('forSection')
            ->andThrow(new OutputEdgeTimingsMissing('missing banked words'));
        $unavailable = $service->assess($section);
        $plan = $section->metadata->raw['publication_candidate_extraction'];
        $plan['segments'][0]['end_time'] = 255.0;
        $this->currentPlan($plan);
        $stale = $service->assess($section);

        $this->assertSame('signature_mismatch', $signatureMismatch['published_cut']['status']);
        $this->assertSame('plan_unavailable', $unavailable['published_cut']['status']);
        $this->assertSame('stale_plan', $stale['published_cut']['status']);
        $this->assertCount(3, array_unique(array_column([$signatureMismatch, $unavailable, $stale], 'inputs_fingerprint')));
        $this->assertSame($stale['inputs_fingerprint'], $service->inputsFingerprint($section));
    }

    #[Test]
    public function it_refuses_a_changed_fade_or_voice_removal_even_with_matching_spans(): void
    {
        $section = $this->recordCandidate($this->spokenTailSection(), 100.0, 250.0, 'song_end_silence');
        $plan = $section->metadata->raw['publication_candidate_extraction'];
        foreach ([
            ['audio_fade_out' => 0.1],
            ['audio_voice_removal' => ['from' => 245.0, 'instruments_start' => 240.0, 'instruments' => ['disk' => 'local', 'path' => 'stems.wav']]],
        ] as $change) {
            $this->currentPlan([...$plan, ...$change]);
            $assessment = $this->policy->assess($section);
            $this->assertSame('stale_plan', $assessment['boundary_evidence']['published_cut']['status']);
            $this->assertContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
        }
    }

    #[Test]
    public function assessing_a_real_current_plan_performs_only_selects_and_no_external_work(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $this->storeBoundaryArtifacts($section, [['start' => 110.0, 'end' => 290.0, 'text' => 'Praise the Lord.']], [['time' => 150.0, 'rms' => -20.0]], 400.0);
        $section = $section->fresh();
        $plan = app(CueSafeExtractionPlan::class)->forSection($section);
        $section->metadata = ServiceSectionMetadata::fromArray(['publication_candidate_extraction' => [
            ...$plan, 'media_signature' => $section->mediaSignature(),
        ]]);
        $section->save();
        $section = $section->fresh();
        $before = $section->getRawOriginal();
        $files = Storage::disk('local')->allFiles();
        $contents = array_map(fn (string $path): string => Storage::disk('local')->get($path), $files);
        foreach (['local', 'public'] as $name) {
            $disk = \Mockery::mock(Storage::disk($name));
            foreach (['put', 'putFile', 'putFileAs', 'writeStream', 'delete', 'deleteDirectory', 'makeDirectory', 'copy', 'move', 'setVisibility'] as $method) {
                $disk->shouldNotReceive($method);
            }
            Storage::set($name, $disk);
        }
        $queries = [];
        $writes = [];
        DB::connection()->beforeExecuting(static function (string $sql) use (&$queries, &$writes): void {
            if (! preg_match('/^\\s*select\\b/i', $sql)) {
                $writes[] = $sql;
                throw new \LogicException('Assessment attempted a non-SELECT: '.$sql);
            }
            $queries[] = $sql;
        });
        Http::preventStrayRequests();
        OpenAI::fake([]);
        Bus::fake();
        Queue::fake();
        Process::fake();

        $assessment = app(SongPublicationBoundaryEvidenceService::class)->assess($section);

        $this->assertSame('used', $assessment['published_cut']['status']);
        $this->assertNotEmpty($queries);
        $this->assertSame([], $writes);
        $this->assertSame($before, $section->fresh()->getRawOriginal());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame($contents, array_map(fn (string $path): string => Storage::disk('local')->get($path), $files));
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        Process::assertNothingRan();
        OpenAI::assertNothingSent();
    }

    #[Test]
    public function it_checks_every_canonical_span_and_both_voice_removal_times_and_identity(): void
    {
        $section = $this->recordCandidate($this->spokenTailSection(), 100.0, 250.0, 'song_end_silence');
        $candidate = $section->metadata->raw['publication_candidate_extraction'];
        $candidate['segments'] = [['start_time' => 100, 'end_time' => 180], ['start_time' => 190, 'end_time' => 250]];
        $candidate['audio_voice_removal'] = ['from' => 245.0, 'instruments_start' => 240.0, 'instruments' => ['disk' => 'local', 'path' => 'stems.wav']];
        $section->metadata = ServiceSectionMetadata::fromArray(['publication_candidate_extraction' => $candidate]);
        foreach ([
            [[], 'used'],
            [['segments' => [['start_time' => '100', 'end_time' => '180'], ['start_time' => '190', 'end_time' => '250']]], 'used'],
            [['segments' => [['start_time' => 100.0, 'end_time' => 180.02], ['start_time' => 190.0, 'end_time' => 250.0]]], 'stale_plan'],
            [['segments' => [['start_time' => 100.0, 'end_time' => 250.0]]], 'stale_plan'],
            [['segments' => [['start_time' => 100.01, 'end_time' => 180.0], ['start_time' => 190.0, 'end_time' => 250.0]]], 'stale_plan'],
            [['audio_voice_removal' => [...$candidate['audio_voice_removal'], 'from' => 245.0009, 'instruments_start' => 240.0009]], 'used'],
            [['audio_voice_removal' => [...$candidate['audio_voice_removal'], 'from' => 245.002]], 'stale_plan'],
            [['audio_voice_removal' => [...$candidate['audio_voice_removal'], 'instruments_start' => 240.002]], 'stale_plan'],
            [['audio_voice_removal' => [...$candidate['audio_voice_removal'], 'instruments' => ['disk' => 'local', 'path' => 'other.wav']]], 'stale_plan'],
        ] as [$change, $status]) {
            $this->currentPlan([...$candidate, ...$change]);
            $this->assertSame($status, $this->policy->assess($section)['boundary_evidence']['published_cut']['status']);
        }
    }

    #[Test]
    public function it_records_missing_and_invalid_candidates_and_missing_transcript_without_accepting_them(): void
    {
        $section = $this->spokenTailSection();
        $this->assertSame('not_recorded', $this->policy->assess($section)['boundary_evidence']['published_cut']['status']);
        $section = $this->recordCandidate($section, 100.0, 250.0, 'song_end_silence');
        $candidate = $section->metadata->raw['publication_candidate_extraction'];
        foreach ([[], [['start_time' => 'bad', 'end_time' => 250.0]], [['start_time' => 250.0, 'end_time' => 100.0]]] as $segments) {
            $section->metadata = ServiceSectionMetadata::fromArray(['publication_candidate_extraction' => [...$candidate, 'segments' => $segments]]);
            $this->assertSame('invalid_candidate', $this->policy->assess($section)['boundary_evidence']['published_cut']['status']);
        }
        $section->metadata = ServiceSectionMetadata::fromArray(['publication_candidate_extraction' => $candidate]);
        Storage::disk('local')->delete($section->processingLog->serviceTranscriptPath());
        $this->mock(CueSafeExtractionPlan::class)->shouldNotReceive('forSection');
        $assessment = $this->policy->assess($section);
        $this->assertSame('plan_unavailable', $assessment['boundary_evidence']['published_cut']['status']);
        $this->assertSame('stored_bounds', $assessment['boundary_evidence']['published_cut']['source']);
    }

    #[Test]
    public function it_falls_back_when_the_planner_returns_a_refused_or_unresolved_edge(): void
    {
        $section = $this->recordCandidate($this->spokenTailSection(), 100.0, 250.0, 'song_end_silence');
        $plan = $section->metadata->raw['publication_candidate_extraction'];
        foreach ([CueSafeExtractionPlan::OPERATOR_EDGE_REFUSED, CueSafeExtractionPlan::AMBIGUOUS_CUE_ANCHOR] as $reason) {
            $current = $plan;
            array_unshift($current['cue_edge_widening'], [
                'span_index' => 0, 'edge' => 'start', 'reason' => $reason,
                'original_time' => 100.0, 'time' => 100.0, 'refusal' => 'banked edge cannot be applied',
            ]);
            $this->currentPlan($current);
            $assessment = $this->policy->assess($section);
            $this->assertContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
            $this->assertSame('plan_unavailable', $assessment['boundary_evidence']['published_cut']['status']);
            $this->assertSame('stored_bounds', $assessment['boundary_evidence']['published_cut']['source']);
        }
    }

    #[Test]
    public function it_propagates_an_unexpected_runtime_planner_failure(): void
    {
        $section = $this->recordCandidate($this->spokenTailSection(), 100.0, 250.0, 'song_end_silence');
        $this->mock(CueSafeExtractionPlan::class)->shouldReceive('forSection')->once()
            ->andThrow(new \RuntimeException('unexpected runtime planner defect'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected runtime planner defect');
        $this->policy->assess($section);
    }

    private function freshnessCase(float $start, float $end, float $gapEnd): ServiceSection
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: $start, end: $end);
        $this->storeBoundaryArtifacts($section, [
            ['start' => $start, 'end' => $gapEnd - 15.0, 'text' => 'The song continues.'],
            ['start' => $gapEnd, 'end' => $end, 'text' => 'Let us pray together.'],
        ], [['time' => $gapEnd - 14.0, 'rms' => -20.0], ['time' => $gapEnd - 7.0, 'rms' => -20.0], ['time' => $gapEnd, 'rms' => -20.0]]);

        return $section->fresh();
    }

    /** @param array<string, mixed> $plan */
    private function currentPlan(array $plan): void
    {
        $this->mock(CueSafeExtractionPlan::class)->shouldReceive('forSection')->andReturn($plan);
    }

    private function spokenTailSection(): ServiceSection
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 300.0);
        $this->storeBoundaryArtifacts($section, [
            ['start' => 100.0, 'end' => 180.0, 'text' => 'The song begins.'],
            ['start' => 181.0, 'end' => 245.0, 'text' => 'The song continues.'],
            ['start' => 260.0, 'end' => 300.0, 'text' => 'Let us pray together now.'],
        ], [['time' => 245.0, 'rms' => -20.0], ['time' => 252.0, 'rms' => -20.0], ['time' => 260.0, 'rms' => -20.0]]);

        return $section->fresh();
    }

    private function shortSpokenTailSection(): ServiceSection
    {
        $section = $this->section('full', ['livestream'], metadata: [], start: 100.0, end: 285.0);
        $this->storeBoundaryArtifacts($section, [
            ['start' => 100.0, 'end' => 225.0, 'text' => 'The song begins.'],
            ['start' => 236.0, 'end' => 280.0, 'text' => 'The song continues.'],
            ['start' => 285.0, 'end' => 293.3, 'text' => 'Let us pray.'],
        ], [
            ['time' => 225.0, 'rms' => -20.0], ['time' => 230.0, 'rms' => -20.0], ['time' => 236.0, 'rms' => -20.0],
            ['time' => 280.0, 'rms' => -20.0], ['time' => 283.0, 'rms' => -20.0], ['time' => 285.0, 'rms' => -20.0],
        ], 400.0);

        return $section->fresh();
    }

    /** Records the cut the planner chose, as `PrepareSectionPublicationCandidates` does. */
    private function recordCandidate(ServiceSection $section, float $start, float $end, string $reason, ?string $signature = null): ServiceSection
    {
        $section->metadata = ServiceSectionMetadata::fromArray([...($section->metadata?->toArray() ?? []),
            'publication_candidate_extraction' => [
                'segments' => [['start_time' => $start, 'end_time' => $end]],
                'cue_edge_widening' => [['span_index' => 0, 'edge' => 'end', 'reason' => $reason, 'original_time' => $end, 'time' => $end]],
                'audio_fade_out' => null,
                'media_signature' => $signature ?? $section->mediaSignature(),
                'candidate_id' => 'test',
            ],
        ]);
        $section->save();
        $this->currentPlan($section->metadata->raw['publication_candidate_extraction']);

        return $section->fresh();
    }

    /**
     * @param  list<string>  $provenance
     * @param  array<string, mixed>  $metadata
     */
    /** A catalogue song other than the one the section under test is assigned. */
    /**
     * 1240 §175520, 10-24: an operator's "start here" took in the sung opening, but the check
     * judged the section's stored start and held the correct cut. It judges the clip as cut.
     */
    #[Test]
    public function it_judges_the_clip_from_the_operators_start(): void
    {
        $section = $this->lyricSection('O Father you are sovereign in all the worlds you made your mighty word was spoken');
        $this->storeLyricArtifacts($section, [
            ['start' => 578.0, 'end' => 586.0, 'text' => 'O Father, you are sovereign.'],
            ['start' => 586.0, 'end' => 594.0, 'text' => 'In all the worlds you made,'],
            ['start' => 600.0, 'end' => 610.0, 'text' => 'Your mighty word was spoken'],
        ], [[0, 1000, 'sung']]);

        $this->assertContains('song_lyrics_outside_section', array_column($this->policy->assess($section)['reasons'], 'kind'));

        $section->metadata = ServiceSectionMetadata::fromArray([...($section->metadata?->toArray() ?? []),
            OutputEdgeAnswers::METADATA_KEY => ['start' => ['time' => 578.0, 'set_by_user_id' => 1, 'set_at' => '2026-10-24T08:24:17+00:00', 'from_time' => 600.0, 'section_span' => ['start_time' => 600.0, 'end_time' => 840.0]]],
        ]);
        $section->save();

        $assessment = $this->policy->assess($section->fresh());

        $this->assertNotContains('song_lyrics_outside_section', array_column($assessment['reasons'], 'kind'));
        $this->assertSame(578.0, $assessment['boundary_evidence']['candidate']['start_time']);
    }

    /**
     * Register D6 as amended 10-24, operator: a start the musical-introduction rule moved reaches
     * a reviewer, who approves it or sets the start by ear. Heard 10-24, 3 of 20 moves took in
     * 5-10 s that was not an introduction (1329, 1232, 1272).
     */
    #[Test]
    public function it_holds_a_song_whose_start_moved_over_its_musical_introduction(): void
    {
        $section = $this->section('full', ['livestream'], metadata: [
            SongMusicalIntroduction::METADATA_KEY => [['from' => 615.0, 'to' => 600.0, 'windows' => 3, 'extended_at' => '2026-10-24T10:00:00+00:00']],
        ]);

        $reasons = $this->policy->assess($section)['reasons'];

        $this->assertContains('song_musical_introduction_moved', array_column($reasons, 'kind'));
    }

    /**
     * 1235 §2918, heard 10-24: the clip opened on the first words after 21 s with no words, and
     * the classifier heard no music there; the introduction was quiet ("First notes at 15:19").
     */
    #[Test]
    public function it_holds_a_clip_opening_on_its_words_after_a_long_silence_with_no_music_heard(): void
    {
        $section = $this->quietOpening(lastWordsEnd: 575.0, music: false);

        $this->assertContains(SongQuietIntroduction::RISK_KIND, array_column($this->policy->assess($section)['reasons'], 'kind'));
    }

    /**
     * 992 §163678 (10-31): the recording ends before "Restore, O Lord" is sung, and the clip is the
     * leader reading its words. The classifier hears no music anywhere in it. Unaccompanied singing
     * sounds the same to it (1252's "Happy Birthday"), so a reviewer hears which it is.
     */
    #[Test]
    public function it_holds_a_song_clip_with_no_music_heard_in_it(): void
    {
        $section = $this->timedSong([[0, 1000, 0.02, 0.85]]);

        $reasons = $this->policy->assess($section)['reasons'];

        $this->assertContains(SongSectionWithoutMusic::RISK_KIND, array_column($reasons, 'kind'));
    }

    #[Test]
    public function it_does_not_hold_a_song_clip_with_music_heard_in_it(): void
    {
        $section = $this->timedSong([[0, 700, 0.02, 0.85], [700, 705, 0.45, 0.3], [705, 1000, 0.02, 0.85]]);

        $reasons = $this->policy->assess($section)['reasons'];

        $this->assertNotContains(SongSectionWithoutMusic::RISK_KIND, array_column($reasons, 'kind'));
    }

    /** A run with no classifier timeline (every weekly run today) has nothing to judge music by. */
    #[Test]
    public function it_does_not_judge_music_without_a_timeline(): void
    {
        $section = $this->section('full', ['livestream']);

        $reasons = $this->policy->assess($section)['reasons'];

        $this->assertNotContains(SongSectionWithoutMusic::RISK_KIND, array_column($reasons, 'kind'));
    }

    #[Test]
    public function it_does_not_hold_a_clip_whose_wordless_time_before_it_is_short(): void
    {
        $section = $this->quietOpening(lastWordsEnd: 592.0, music: false);

        $this->assertNotContains(SongQuietIntroduction::RISK_KIND, array_column($this->policy->assess($section)['reasons'], 'kind'));
    }

    /** Music the classifier heard is the introduction rule's to place, not a quiet one. */
    #[Test]
    public function it_does_not_hold_a_clip_whose_wordless_time_before_it_is_music(): void
    {
        $section = $this->quietOpening(lastWordsEnd: 575.0, music: true);

        $this->assertNotContains(SongQuietIntroduction::RISK_KIND, array_column($this->policy->assess($section)['reasons'], 'kind'));
    }

    private function quietOpening(float $lastWordsEnd, bool $music): ServiceSection
    {
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $section = $this->section('full', ['livestream']);
        $this->storeBoundaryArtifacts($section, [
            ['start' => $lastWordsEnd - 4.0, 'end' => $lastWordsEnd, 'text' => 'So let us stand and sing.'],
            ['start' => 600.5, 'end' => 606.0, 'text' => 'The song begins.'],
        ], [['time' => 590.0, 'rms' => -20.0], ['time' => 600.0, 'rms' => -20.0], ['time' => 840.0, 'rms' => -20.0]], duration: 1000.0);
        $path = 'service-transcripts/test-'.$section->processingLog->processing_id.'.classes.json';
        Storage::disk('local')->put($path, AudioTimelineFixture::json([[0, $lastWordsEnd, 0.02, 0.85], [$lastWordsEnd, 600, $music ? 0.7 : 0.03, 0.02], [600, 1000, 0.7, 0.02]], 1000.0));
        $section->processingLog->forceFill(['audio_timeline_path' => $path])->save();

        return $section->fresh();
    }

    /** @param list<array{0: float, 1: float, 2: float, 3: float}> $windows */
    private function timedSong(array $windows): ServiceSection
    {
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $section = $this->section('full', ['livestream']);
        $path = 'service-transcripts/test-'.$section->processingLog->processing_id.'.classes.json';
        Storage::disk('local')->put($path, AudioTimelineFixture::json($windows, 1000.0));
        $section->processingLog->forceFill(['audio_timeline_path' => $path])->save();

        return $section->fresh();
    }

    private function otherSongId(): int
    {
        return Song::factory()->create()->id;
    }

    /**
     * 1337 §4274/§4275: the order of service printed #699 twice, so the next song
     * section was bound to the same hymn 12 s after the first ended. Nothing lies
     * between them, so they are neighbours in the service whatever the gap.
     */
    #[Test]
    public function it_holds_a_song_whose_next_section_is_the_same_song_across_a_gap(): void
    {
        $section = $this->section('full', ['livestream'], start: 1478.0, end: 1647.0);
        $this->addNeighbour($section, ServiceSectionType::Song, 1659.0, 1767.0, sameSong: true);

        $this->assertContains('adjacent_same_song', array_column($this->policy->reviewReasons($section->fresh()), 'kind'));
    }

    /**
     * A hymn sung again after a prayer is a reprise, not a duplicate binding.
     */
    #[Test]
    public function it_releases_the_same_song_sung_again_after_another_section(): void
    {
        $section = $this->section('full', ['livestream'], start: 1478.0, end: 1647.0);
        $this->addNeighbour($section, ServiceSectionType::Prayer, 1650.0, 1655.0, sameSong: false);
        $this->addNeighbour($section, ServiceSectionType::Song, 1659.0, 1767.0, sameSong: true);

        $this->assertNotContains('adjacent_same_song', array_column($this->policy->reviewReasons($section->fresh()), 'kind'));
    }

    /**
     * Section 306 (2026-07-05): a publicly released 510-second clip issued for
     * "All creatures of our God and King". Its `additional_song_matches` is
     * empty, so the OCR route cannot see it — but the printed order's next song,
     * "King Of The Ages", has no section anywhere in the service.
     */
    #[Test]
    public function it_holds_an_over_long_clip_whose_next_printed_song_has_no_section(): void
    {
        $section = $this->section('full', ['livestream'], start: 304.0, end: 814.0);
        $this->printFollowingSong($section, 'King Of The Ages', located: false);

        $reasons = $this->policy->reviewReasons($section->fresh());

        $this->assertContains('unlocated_adjacent_song', array_column($reasons, 'kind'));
        $this->assertStringContainsString(
            'King Of The Ages',
            $reasons[array_search('unlocated_adjacent_song', array_column($reasons, 'kind'), true)]['detail'],
        );
    }

    /**
     * The printed song was found, so nothing was swallowed.
     */
    #[Test]
    public function it_releases_an_over_long_clip_whose_next_printed_song_was_located(): void
    {
        $section = $this->section('full', ['livestream'], start: 304.0, end: 814.0);
        $this->printFollowingSong($section, 'King Of The Ages', located: true);

        $this->assertNotContains(
            'unlocated_adjacent_song',
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /**
     * An unlocated printed song is ordinary on its own — only 19 of 326 services
     * have none — so an ordinary-length clip must not be held by it. A clip
     * cannot have absorbed a whole further song without running long.
     */
    #[Test]
    public function it_releases_an_ordinary_length_clip_whose_next_printed_song_is_unlocated(): void
    {
        $section = $this->section('full', ['livestream'], start: 600.0, end: 840.0);
        $this->printFollowingSong($section, 'King Of The Ages', located: false);

        $this->assertNotContains(
            'unlocated_adjacent_song',
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /**
     * Print a song after this section's own item in the order of service, and
     * say whether any section claims it.
     */
    private function printFollowingSong(ServiceSection $section, string $title, bool $located): void
    {
        $item = $section->churchServiceItem;
        $item->forceFill(['type' => 'songs', 'position' => 1])->save();

        $following = ChurchServiceItem::factory()->create([
            'church_service_id' => $item->church_service_id,
            'song_id' => Song::factory()->create()->id,
            'type' => 'songs',
            'position' => 2,
            'title' => $title,
        ]);

        if (! $located) {
            return;
        }

        ServiceSection::factory()->create([
            'media_processing_log_id' => $section->media_processing_log_id,
            'church_service_item_id' => $following->id,
            'section_type' => ServiceSectionType::Song->value,
            'start_time' => 900.0,
            'end_time' => 1100.0,
            'duration' => 200.0,
        ]);
    }

    /** S8: a transcription fault alone says nothing about the delivered song's sound. */
    #[Test]
    public function it_records_but_does_not_hold_a_clip_for_a_decode_loop(): void
    {
        $section = $this->section('full', ['livestream']);
        $this->recordLoopBlocks($section, 600.0, 760.0);

        $assessment = $this->policy->assess($section->fresh());
        $this->assertNotEmpty($assessment['boundary_evidence']['looped_transcript']);
        $this->assertNotContains(
            SongLoopedTranscript::RISK_KIND,
            array_column($assessment['reasons'], 'kind'),
        );
    }

    /**
     * A chorus repeats because the song repeats, however much of the section it covers.
     *
     * This test used to assert that a *brief* loop is released, on the reasoning that it sits
     * under the share that withholds a clip. Adjudicating the corpus on 2026-09-16 overturned
     * that: below the half-loop line, 59 of 85 sections repeat phrases their song does not
     * contain — a 69% defect rate against 80% above it. The share barely discriminates, so the
     * phrase decides, and what earns release is the words being the song's own.
     */
    #[Test]
    public function it_releases_a_clip_whose_looped_phrase_is_the_song_repeating_itself(): void
    {
        $section = $this->section('full', ['livestream']);
        $this->recordLoopBlocks($section, 600.0, 760.0, phrase: self::SONGS_OWN_PHRASE);

        $this->assertNotContains(
            SongLoopedTranscript::RISK_KIND,
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /** S8 also covers brief loops, even when the hallucinated words are not lyrics. */
    #[Test]
    public function it_does_not_hold_a_clip_for_a_brief_transcription_loop(): void
    {
        $section = $this->section('full', ['livestream']);
        $this->recordLoopBlocks($section, 600.0, 620.0);

        $this->assertNotContains(
            SongLoopedTranscript::RISK_KIND,
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /**
     * Record a repetition block on the section's run.
     *
     * Deliberately after {@see self::storeBoundaryArtifacts()} and on the same transcript key:
     * `putServiceTranscriptPath()` reads a null block list as "this transcript was replaced,
     * forget the screen", so recording blocks first would wipe them and a different key would
     * orphan the artifacts the boundary evidence reads.
     */
    /**
     * The control for the discount below. Without it the discount test could pass because the
     * fixture never raised a framing risk at all, which would prove nothing.
     */
    #[Test]
    public function it_reads_a_clip_whose_opening_gap_follows_spoken_framing_as_an_introduction(): void
    {
        $section = $this->framedSection();

        $this->assertSame('spoken_introduction', $this->policy->assess($section)['boundary_evidence']['start_evidence']['basis']);
    }

    /**
     * A looping transcript's cues are not a record of what was said, so the silence between two
     * of them is not evidence of where the singing began. That is the same reason an unobservable
     * window discounts a gap: in both cases the transcript was never a witness to that stretch.
     */
    #[Test]
    public function it_does_not_read_a_gap_inside_a_loop_as_spoken_framing(): void
    {
        $section = $this->framedSection();
        $this->recordLoopBlocks($section, 602.0, 650.0);

        $assessment = $this->policy->assess($section->fresh());

        $this->assertNotContains('song_boundary_spoken_framing', array_column($assessment['reasons'], 'kind'));

        // A discount is the absence of a risk, so it is recorded in the evidence a reviewer
        // reads rather than in the reasons that withhold the clip — as `unobservable_gap` is.
        $this->assertSame('looped_gap', $assessment['boundary_evidence']['start_evidence']['basis']);
    }

    /**
     * The loop here repeats the song's own words, so the section is not demoted and the gap
     * discount is unambiguously the thing under test. Isolating it that way matters more since
     * the demotion stopped depending on the share: a phrase the song lacks now withholds the
     * clip at any coverage, which would mask what this test exists to prove.
     */
    #[Test]
    public function it_discounts_the_gap_without_demoting_the_whole_section(): void
    {
        $section = $this->framedSection();
        $this->recordLoopBlocks($section, 602.0, 650.0, phrase: self::SONGS_OWN_PHRASE);

        $this->assertNotContains(
            SongLoopedTranscript::RISK_KIND,
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /** The control for the trailing discount: this fixture really does raise a trailing risk. */
    #[Test]
    public function it_holds_a_clip_whose_closing_gap_precedes_further_content(): void
    {
        $section = $this->tailGapSection();

        $this->assertContains(
            'song_boundary_trailing_content',
            array_column($this->policy->reviewReasons($section), 'kind'),
        );
    }

    /**
     * The same reasoning at the other edge. A gap between two looped cues says nothing about
     * whether the singing had stopped, so it cannot be evidence of content after the song.
     */
    #[Test]
    public function it_does_not_read_a_gap_inside_a_loop_as_trailing_content(): void
    {
        $section = $this->tailGapSection();
        $this->recordLoopBlocks($section, 788.0, 812.0);

        $assessment = $this->policy->assess($section->fresh());

        $this->assertNotContains('song_boundary_trailing_content', array_column($assessment['reasons'], 'kind'));
        $this->assertSame('looped_gap', $assessment['boundary_evidence']['end_evidence']['basis']);
    }

    /**
     * A song section whose singing is followed by an audio-backed wordless gap near its end,
     * with thirty seconds of further content after it.
     */
    private function tailGapSection(): ServiceSection
    {
        config([
            'media-processing.segmentation.adaptive_thresholds.enabled' => false,
            'media-processing.segmentation.rms_threshold' => -45.0,
        ]);

        $section = $this->section('full', ['livestream']);

        $samples = [];

        for ($tenth = 0; $tenth <= 9000; $tenth++) {
            $time = $tenth / 10;
            $samples[] = ['time' => $time, 'rms' => ($time >= 600.0 && $time <= 840.0) ? -20.0 : -60.0];
        }

        $this->storeBoundaryArtifacts(
            $section,
            [
                ['start' => 600.0, 'end' => 790.0, 'text' => 'Amazing grace how sweet the sound that saved a wretch like me.'],
                ['start' => 810.0, 'end' => 840.0, 'text' => 'Let us pray together now.'],
            ],
            $samples,
            duration: 900.0,
        );

        return $section->fresh();
    }

    /**
     * A song section whose opening cue is followed by an audio-backed wordless gap: spoken
     * framing five seconds in, which the boundary evidence holds for review.
     */
    private function framedSection(): ServiceSection
    {
        config([
            'media-processing.segmentation.adaptive_thresholds.enabled' => false,
            'media-processing.segmentation.rms_threshold' => -45.0,
        ]);

        $section = $this->section('full', ['livestream']);

        $samples = [];

        for ($tenth = 0; $tenth <= 9000; $tenth++) {
            $time = $tenth / 10;
            $samples[] = ['time' => $time, 'rms' => ($time >= 600.0 && $time <= 840.0) ? -20.0 : -60.0];
        }

        $this->storeBoundaryArtifacts(
            $section,
            [
                ['start' => 600.0, 'end' => 605.0, 'text' => 'Let us stand and sing together.'],
                ['start' => 640.0, 'end' => 840.0, 'text' => 'Amazing grace how sweet the sound.'],
            ],
            $samples,
            duration: 900.0,
        );

        return $section->fresh();
    }

    private function recordLoopBlocks(ServiceSection $section, float $start, float $end, string $phrase = 'for the lord i will stand'): void
    {
        $log = $section->processingLog;

        $log->putServiceTranscriptPath(
            'service-transcripts/test-'.$log->processing_id.'.normalized.json',
            [],
            [new SuspectTranscriptBlock(
                start: $start,
                end: $end,
                reason: SuspectTranscriptBlock::REASON_REPEATED_PHRASE,
                words: 160,
                wordsPerMinute: 120.0,
                phrase: $phrase,
                repeats: 40,
            )->toArray()],
        );
    }

    /**
     * The phrase the section's bound song actually contains.
     *
     * {@see SongFactory} writes `Verse line one` as the catalogue lyrics, so
     * a loop repeating that is the congregation singing rather than the decode looping.
     */
    private const SONGS_OWN_PHRASE = 'Verse line one';

    private function addNeighbour(ServiceSection $section, ServiceSectionType $type, float $start, float $end, bool $sameSong): ServiceSection
    {
        $item = $sameSong
            ? ChurchServiceItem::factory()->create([
                'church_service_id' => $section->churchServiceItem->church_service_id,
                'song_id' => $section->churchServiceItem->song_id,
                'source' => ChurchServiceItemSource::Livestream,
            ])
            : null;

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $section->media_processing_log_id,
            'church_service_item_id' => $item?->id,
            'section_type' => $type->value,
            'song_match_type' => $sameSong ? ServiceSectionSongMatchType::Confirmed->value : null,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
        ]);
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
     * 1291 §3640 (clip 360) and 1222: a song's first lines are sung inside the tail of the song
     * before it, because the boundary between the two came late.
     */
    #[Test]
    public function it_holds_a_clip_whose_opening_lines_are_sung_inside_the_previous_song(): void
    {
        $section = $this->lyricSection('God of the ages history\'s maker planning our pathway holding us fast');
        $previous = $this->neighbour($section, 'song', 380.0, 600.0, 'Great is thy faithfulness O God my Father morning by morning new mercies I see');
        $this->storeLyricArtifacts($section, [
            ['start' => 400.0, 'end' => 420.0, 'text' => 'Great is thy faithfulness, O God my Father.'],
            ['start' => 560.0, 'end' => 570.0, 'text' => 'God of the ages, history\'s maker,'],
            ['start' => 570.0, 'end' => 580.0, 'text' => 'planning our pathway, holding us fast,'],
            ['start' => 620.0, 'end' => 830.0, 'text' => 'God of the ages, history\'s maker.'],
        ], [[380, 1000, 'sung']]);

        $reasons = $this->policy->reviewReasons($section->fresh());

        $this->assertContains('song_lyrics_outside_section', array_column($reasons, 'kind'));
        $detail = $reasons[array_search('song_lyrics_outside_section', array_column($reasons, 'kind'), true)]['detail'];
        $this->assertStringContainsString('before', $detail);
        $this->assertStringContainsString((string) $previous->id, $detail);
    }

    /**
     * 1108 §1896 (clip 240): the final verse and chorus run on inside the prayer that follows.
     */
    #[Test]
    public function it_holds_a_clip_whose_last_verse_is_sung_inside_the_following_prayer(): void
    {
        $section = $this->lyricSection('Bless the Lord O my soul worship his holy name ten thousand years and then forevermore');
        $this->neighbour($section, 'prayer', 840.0, 1000.0);
        $this->storeLyricArtifacts($section, [
            ['start' => 620.0, 'end' => 830.0, 'text' => 'Bless the Lord, O my soul.'],
            ['start' => 850.0, 'end' => 860.0, 'text' => 'Ten thousand years and then forevermore.'],
            ['start' => 860.0, 'end' => 870.0, 'text' => 'Bless the Lord, O my soul, worship his holy name.'],
            ['start' => 885.0, 'end' => 990.0, 'text' => 'Lord, we thank you for this morning.'],
        ], [[580, 875, 'sung'], [875, 1000, 'speech']]);

        $this->assertContains(
            'song_lyrics_outside_section',
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /**
     * 1337 §4268 (clip 409) and 1291 §3642: a prayer or talk quotes the hymn. Spoken lines pause
     * between phrases, so the sound under them is not sustained.
     */
    #[Test]
    public function it_releases_a_clip_whose_lyrics_are_quoted_in_speech_beside_it(): void
    {
        $section = $this->lyricSection('Bless the Lord O my soul worship his holy name ten thousand years and then forevermore');
        $this->neighbour($section, 'prayer', 840.0, 1000.0);
        $this->storeLyricArtifacts($section, [
            ['start' => 620.0, 'end' => 830.0, 'text' => 'Bless the Lord, O my soul.'],
            ['start' => 850.0, 'end' => 860.0, 'text' => 'Ten thousand years and then forevermore.'],
            ['start' => 860.0, 'end' => 870.0, 'text' => 'Bless the Lord, O my soul, worship his holy name.'],
        ], [[580, 840, 'sung'], [840, 1000, 'speech']]);

        $this->assertNotContains(
            'song_lyrics_outside_section',
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /**
     * 1262 §3283: "praise him" and "adore him" are in many hymns. A line the neighbouring song's
     * own lyrics explain better is that song being sung, not this one.
     */
    #[Test]
    public function it_releases_a_clip_when_the_line_belongs_to_the_neighbouring_song(): void
    {
        $section = $this->lyricSection('All people that on earth do dwell sing to the Lord with cheerful voice praise him');
        $this->neighbour($section, 'song', 380.0, 600.0, 'Praise my soul the King of heaven praise him praise him angels help us to adore him');
        $this->storeLyricArtifacts($section, [
            ['start' => 560.0, 'end' => 570.0, 'text' => 'Praise him, praise him, angels help us to adore him.'],
            ['start' => 575.0, 'end' => 585.0, 'text' => 'Praise him, praise him, angels help us to adore him.'],
            ['start' => 620.0, 'end' => 830.0, 'text' => 'All people that on earth do dwell.'],
        ], [[380, 1000, 'sung']]);

        $this->assertNotContains(
            'song_lyrics_outside_section',
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /**
     * 1224 and 1127: the leader names the song before it starts, over the introduction.
     */
    #[Test]
    public function it_releases_a_clip_whose_title_is_only_announced_beside_it(): void
    {
        $section = $this->lyricSection('Lord I lift your name on high Lord I love to sing your praises');
        $this->neighbour($section, 'welcome', 380.0, 600.0);
        $this->storeLyricArtifacts($section, [
            ['start' => 570.0, 'end' => 590.0, 'text' => 'It\'s number 314, let\'s stand and sing Lord I lift your name on high.'],
            ['start' => 620.0, 'end' => 830.0, 'text' => 'Lord, I lift your name on high.'],
        ], [[380, 1000, 'sung']]);

        $this->assertNotContains(
            'song_lyrics_outside_section',
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /**
     * 1266 §3332: the next section has no identified song, and its transcript loops one garbled
     * line 37 times over a different song. The loop shared three word pairs with this song.
     */
    #[Test]
    public function it_releases_a_clip_when_the_matching_line_beside_it_is_a_transcription_loop(): void
    {
        $section = $this->lyricSection('Jesus is Lord the judge of all will take his children home');
        $this->neighbour($section, 'song', 840.0, 1000.0);
        $loop = array_map(
            static fn (int $index): array => ['start' => 850.0 + $index, 'end' => 851.0 + $index, 'text' => 'The judge will take his children home.'],
            range(0, 36),
        );
        $this->storeLyricArtifacts($section, [
            ['start' => 620.0, 'end' => 830.0, 'text' => 'Jesus is Lord.'],
            ...$loop,
        ], [[580, 1000, 'sung']]);

        $this->assertNotContains(
            'song_lyrics_outside_section',
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /**
     * 1035 §4813: the clip opens on verse 2; verse 1 was sung before it and never transcribed.
     */
    #[Test]
    public function it_holds_a_clip_that_opens_after_its_first_verse_while_the_church_sings(): void
    {
        $section = $this->section('full', ['livestream']);
        Song::query()->whereKey($section->churchServiceItem->song_id)->update([
            'lyrics_xml' => '<song><lyrics>'
                .'<verse type="v" label="1"><![CDATA[O little town of Bethlehem how still we see thee lie]]></verse>'
                .'<verse type="v" label="2"><![CDATA[For Christ is born of Mary and gathered all above]]></verse>'
                .'<verse type="v" label="3"><![CDATA[How silently how silently the wondrous gift is given]]></verse>'
                .'</lyrics></song>',
            'lyrics_plain' => "O little town of Bethlehem how still we see thee lie\n\nFor Christ is born of Mary and gathered all above\n\nHow silently how silently the wondrous gift is given",
            'verse_order' => null,
        ]);
        $this->storeLyricArtifacts($section->fresh(), [
            ['start' => 601.0, 'end' => 610.0, 'text' => 'For Christ is born of Mary, and gathered all above.'],
            ['start' => 820.0, 'end' => 830.0, 'text' => 'How silently, how silently, the wondrous gift is given.'],
        ], [[560, 900, 'sung']]);

        $this->assertContains(
            'song_opening_missing',
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /**
     * 938 §622 (clip 74): the leader announces the song and the section ends before anyone sings.
     */
    #[Test]
    public function it_holds_a_short_clip_that_only_announces_its_song(): void
    {
        $section = $this->shortLyricSection('Lord I lift your name on high Lord I love to sing your praises I\'m so glad you\'re in my life');
        $this->storeLyricArtifacts($section, [
            ['start' => 602.0, 'end' => 618.0, 'text' => 'Well, let\'s stand, shall we, and sing. It\'s number 314.'],
        ], [[580, 700, 'speech']]);

        $this->assertContains(
            'song_section_without_song',
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /** 1341 §4310: short, and the bound song's own lines are sung. */
    #[Test]
    public function it_releases_a_short_clip_that_sings_its_song(): void
    {
        $section = $this->shortLyricSection('Lord I lift your name on high Lord I love to sing your praises I\'m so glad you\'re in my life');
        $this->storeLyricArtifacts($section, [
            ['start' => 602.0, 'end' => 618.0, 'text' => 'Lord, I love to sing your praises, I\'m so glad you\'re in my life.'],
        ], [[580, 700, 'sung']]);

        $this->assertNotContains(
            'song_section_without_song',
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /**
     * 1268 §4731: the song's own line looped over the prayer the section swallowed. The phrase is
     * in the lyrics, so the text rule passes it; the sound under the loop is speech.
     */
    #[Test]
    public function it_holds_a_clip_whose_loop_sits_over_speech(): void
    {
        $section = $this->lyricSection('We honour and adore you Lord, we lift our hearts to you');
        $this->neighbour($section, 'song', 880.0, 990.0, 'Crown him with many crowns the Lamb upon his throne');
        $this->storeLyricArtifacts($section, [
            ['start' => 620.0, 'end' => 830.0, 'text' => 'We honour and adore you.'],
        ], [[580, 700, 'sung'], [700, 840, 'speech'], [880, 990, 'sung']]);
        $this->recordLoopBlocks($section, 700.0, 830.0, 'we honour and adore you');

        $this->assertContains(
            'song_speech_under_loop',
            array_column($this->policy->reviewReasons($section->fresh()), 'kind'),
        );
    }

    /**
     * A 20 s confirmed song section from 600 s whose catalogue song carries these lyrics.
     */
    private function shortLyricSection(string $lyrics): ServiceSection
    {
        $section = $this->lyricSection($lyrics);
        $section->forceFill(['end_time' => 620.0, 'duration' => 20.0])->save();

        return $section->fresh();
    }

    /**
     * A confirmed song section from 600 s to 840 s whose catalogue song carries these lyrics.
     */
    private function lyricSection(string $lyrics): ServiceSection
    {
        $section = $this->section('full', ['livestream']);
        Song::query()->whereKey($section->churchServiceItem->song_id)->update(['lyrics_plain' => $lyrics]);

        return $section->fresh();
    }

    /**
     * Another section on the same run; a song neighbour gets its own catalogue song and lyrics.
     */
    private function neighbour(ServiceSection $section, string $type, float $start, float $end, ?string $lyrics = null): ServiceSection
    {
        $itemId = null;

        if ($lyrics !== null) {
            $itemId = ChurchServiceItem::factory()->create([
                'church_service_id' => $section->churchServiceItem->church_service_id,
                'song_id' => Song::factory()->create(['lyrics_plain' => $lyrics])->id,
            ])->id;
        }

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $section->media_processing_log_id,
            'church_service_item_id' => $itemId,
            'section_type' => $type,
            'song_match_type' => $lyrics !== null ? ServiceSectionSongMatchType::Confirmed->value : null,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
        ]);
    }

    /**
     * Transcript cues over a 1000 s recording, and an RMS log sampled every 0.1 s: singing holds
     * -18 dB without a break, speech pauses half a second every 3 s, anything unlisted is
     * silence. A fixed threshold keeps the adaptive percentile from reading the singing as quiet.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{0: int, 1: int, 2: 'sung'|'speech'}>  $spans
     */
    private function storeLyricArtifacts(ServiceSection $section, array $cues, array $spans): void
    {
        config([
            'media-processing.segmentation.adaptive_thresholds.enabled' => false,
            'media-processing.segmentation.rms_threshold' => -45.0,
        ]);

        $samples = [];

        for ($tenth = 0; $tenth < 10000; $tenth++) {
            $time = $tenth / 10;
            $level = -60.0;

            foreach ($spans as [$from, $to, $kind]) {
                if ($time >= $from && $time < $to) {
                    $level = $kind === 'sung' ? -18.0 : (fmod($time, 3.0) < 2.5 ? -25.0 : -60.0);
                }
            }

            $samples[] = ['time' => $time, 'rms' => $level];
        }

        $this->storeBoundaryArtifacts($section, $cues, $samples, duration: 1000.0);
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{time: float, rms: float}>  $samples
     */
    private function storeBoundaryArtifacts(ServiceSection $section, array $cues, array $samples, ?float $duration = null): void
    {
        $log = $section->processingLog;
        $transcriptPath = 'service-transcripts/test-'.$log->processing_id.'.normalized.json';
        $rmsPath = 'service-transcripts/test-'.$log->processing_id.'.rms.json';

        Storage::disk('local')->put($transcriptPath, json_encode([
            'cues' => $cues,
            'duration' => $duration ?? (float) $section->end_time,
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
