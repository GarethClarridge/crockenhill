<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeAnswers;
use App\Services\ChurchService\OutputEdgeCheck;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\PublicationPlanValidator;
use App\Services\ChurchService\SermonSongIntroductionCheck;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

/**
 * Q12 (operator, 2026-10-16): no tool could set a sermon or talk edge the planner does not refuse.
 * 1224's sermon end ran 22 s into the hymn while the preacher read it; 1111's and 1198's ends were
 * moved outward to a mid-sentence pause in the next cue. An operator's "end here" or "start here"
 * is a recording time chosen by ear: the planner applies it to its own output after every edge rule
 * and word-timing move, and refuses it, with a reason, where it would cut into other content.
 */
class OperatorOutputEdgeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 1224: the preacher reads the hymn's first verse over its introduction, and the sermon's end
     * was carried 22 s into the hymn. The operator ends it where they hear the sermon end.
     */
    #[Test]
    public function an_end_set_by_ear_replaces_a_sermon_end_carried_into_the_hymn(): void
    {
        [$log, $sermon] = $this->sermonBesideTheHymn();
        $spans = [['start_time' => 2545.22, 'end_time' => 4007.22]];

        $planned = $this->plans()->forSpans($log, $spans, sermonEnd: true, edgeAnswers: OutputEdgeAnswers::of($sermon, [$sermon]));
        $this->assertGreaterThan(4010.0, $planned['segments'][0]['end_time'], 'the precondition: the planned end runs on into the hymn');

        $this->setEdge($sermon, 'end', 4007.6);
        $answered = $this->plans()->forSpans($log->fresh(), $spans, sermonEnd: true, edgeAnswers: OutputEdgeAnswers::of($sermon->fresh(), [$sermon]));

        $this->assertSame(4007.6, $answered['segments'][0]['end_time']);
        $entry = collect($answered['cue_edge_widening'])->firstWhere('edge', 'end');
        $this->assertSame(CueSafeExtractionPlan::OPERATOR_SET_EDGE, $entry['reason']);
        $this->assertEqualsWithDelta($planned['segments'][0]['end_time'], $entry['planned_time'], 0.001, 'the planner\'s own edge is kept beside the answer');
        $this->assertSame($sermon->id, $entry['operator_edge']['section_id']);
        $this->assertSame([], CueSafeExtractionPlan::refusedOperatorEdges($answered['cue_edge_widening']));
    }

    /**
     * 1111 and 1198: the section ends inside a line, and the word pause the planner takes is in the
     * next line, mid-sentence. The answer is not moved to a pause: it is where the operator heard
     * the sermon end.
     */
    #[Test]
    public function an_end_set_by_ear_is_not_moved_by_word_timings(): void
    {
        [$log, $sermon] = $this->sermonEndingInsideALine();
        $spans = [['start_time' => 1694.88, 'end_time' => 3024.83]];
        $planned = $this->plans()->forSpans($log, $spans, sermonEnd: true);
        $this->assertGreaterThan(3025.5, $planned['segments'][0]['end_time'], 'the precondition: the end moved into the next line');

        $this->setEdge($sermon, 'end', 3023.95);
        $answered = $this->plans()->forSpans($log->fresh(), $spans, sermonEnd: true, edgeAnswers: OutputEdgeAnswers::of($sermon->fresh(), [$sermon]));

        $this->assertSame(3023.95, $answered['segments'][0]['end_time'], 'inside a word pause or not, the operator\'s time stands');
    }

    /** E18's ordering: an answered edge applies after the dead-feed rule, which never moves it. */
    #[Test]
    public function a_start_set_by_ear_applies_after_the_dead_feed_rule(): void
    {
        [$log, $sermon] = $this->sermonAfterADeadFeed();
        $spans = [['start_time' => 10.0, 'end_time' => 40.0]];
        $planned = $this->plans()->forSpans($log, $spans, sermonEnd: true);
        $this->assertEqualsWithDelta(14.7, $planned['segments'][0]['start_time'], 0.001, 'the precondition: the dead-feed rule moves the start');

        $this->setEdge($sermon, 'start', 12.0);
        $answered = $this->plans()->forSpans($log->fresh(), $spans, sermonEnd: true, edgeAnswers: OutputEdgeAnswers::of($sermon->fresh(), [$sermon]));

        $this->assertSame(12.0, $answered['segments'][0]['start_time']);
        $this->assertSame(CueSafeExtractionPlan::OPERATOR_SET_EDGE, collect($answered['cue_edge_widening'])->firstWhere('edge', 'start')['reason']);
    }

    /** A sermon output starting with its reading: "start here" moves the output's start, the reading's. */
    #[Test]
    public function the_answers_are_the_outputs_edges_on_its_first_and_last_parts(): void
    {
        [$log, $sermon] = $this->sermonBesideTheHymn();
        $reading = $log->serviceSections()->where('section_type', ServiceSectionType::BibleReading->value)->firstOrFail();
        $this->setEdge($sermon, 'start', 2094.5);
        $this->setEdge($sermon, 'end', 4007.6);
        $spans = [['start_time' => 2093.92, 'end_time' => 2372.83], ['start_time' => 2545.22, 'end_time' => 4007.22]];

        $answered = $this->plans()->forSpans($log->fresh(), $spans, sermonEnd: true, edgeAnswers: OutputEdgeAnswers::of($sermon->fresh(), [$reading, $sermon]));

        $this->assertSame(2094.5, $answered['segments'][0]['start_time']);
        $this->assertSame(4007.6, $answered['segments'][array_key_last($answered['segments'])]['end_time']);
    }

    /** One answer applies only to its own output: the hymn's clip and other outputs keep their own edges. */
    #[Test]
    public function an_answer_applies_to_no_other_output(): void
    {
        [$log, $sermon] = $this->sermonBesideTheHymn();
        $this->setEdge($sermon, 'end', 4007.6);
        $spans = [['start_time' => 2545.22, 'end_time' => 4007.22]];

        $unowned = $this->plans()->forSpans($log->fresh(), $spans, sermonEnd: true);

        $this->assertGreaterThan(4010.0, $unowned['segments'][0]['end_time'], 'a plan not of this sermon\'s output reads no answer');
    }

    /**
     * 1111: the next section, the introduction to the closing song, starts where the sermon ends.
     * An end set 15 s into it would cut its content into the sermon: refused, the planner's edge
     * kept, and the plan invalid until the answer is changed.
     */
    #[Test]
    public function an_answer_that_cuts_into_another_sections_content_is_refused_with_its_reason(): void
    {
        [$log, $sermon] = $this->sermonEndingInsideALine();
        $spans = [['start_time' => 1694.88, 'end_time' => 3024.83]];
        $planned = $this->plans()->forSpans($log, $spans, sermonEnd: true);

        $this->setEdge($sermon, 'end', 3040.0);
        $answered = $this->plans()->forSpans($log->fresh(), $spans, sermonEnd: true, edgeAnswers: OutputEdgeAnswers::of($sermon->fresh(), [$sermon]));

        $this->assertSame($planned['segments'][0]['end_time'], $answered['segments'][0]['end_time']);
        $refused = CueSafeExtractionPlan::refusedOperatorEdges($answered['cue_edge_widening']);
        $this->assertCount(1, $refused);
        $this->assertStringContainsString('Introduction to “O I Have Heard The Gospel Told”', $refused[0]['refusal']);
        $violations = app(PublicationPlanValidator::class)->validate($log->fresh(), [$sermon], $answered['segments'], $answered['cue_edge_widening']);
        $this->assertContains(['kind' => 'operator_edge_refused', 'section_ids' => [$sermon->id]], $violations);
    }

    /** An end before the last part starts would drop it: refused, never cut. */
    #[Test]
    public function an_answer_that_would_drop_a_part_is_refused(): void
    {
        [$log, $sermon] = $this->sermonBesideTheHymn();
        $this->setEdge($sermon, 'end', 2500.0);
        $spans = [['start_time' => 2545.22, 'end_time' => 4007.22]];

        $answered = $this->plans()->forSpans($log->fresh(), $spans, sermonEnd: true, edgeAnswers: OutputEdgeAnswers::of($sermon->fresh(), [$sermon]));

        $this->assertGreaterThan(2545.22, $answered['segments'][0]['end_time']);
        $this->assertCount(1, CueSafeExtractionPlan::refusedOperatorEdges($answered['cue_edge_widening']));
    }

    /** An end set inward, still inside the hymn the planner already reached into, is no new content. */
    #[Test]
    public function an_inward_answer_inside_content_the_plan_already_reaches_is_kept(): void
    {
        [$log, $sermon] = $this->sermonBesideTheHymn();
        $this->setEdge($sermon, 'end', 4015.0);
        $spans = [['start_time' => 2545.22, 'end_time' => 4007.22]];

        $answered = $this->plans()->forSpans($log->fresh(), $spans, sermonEnd: true, edgeAnswers: OutputEdgeAnswers::of($sermon->fresh(), [$sermon]));

        $this->assertSame(4015.0, $answered['segments'][0]['end_time']);
        $this->assertSame([], CueSafeExtractionPlan::refusedOperatorEdges($answered['cue_edge_widening']));
    }

    /** The panel's check resolves the next cut read only: placed when decoded, pending before. */
    #[Test]
    public function the_check_reports_each_edge_without_writing_anything(): void
    {
        [$log, $sermon] = $this->sermonEndingInsideALine();
        $this->setEdge($sermon, 'end', 3023.95);
        $before = $log->fresh()->processing_metadata?->toArray();

        $checked = app(OutputEdgeCheck::class)->check($sermon->fresh());

        $this->assertSame('checked', $checked['status']);
        $this->assertSame(3023.95, $checked['edges']['end']['cut']);
        $this->assertSame(3023.95, $checked['edges']['end']['answer']);
        $this->assertGreaterThan(3025.5, $checked['edges']['end']['planned']);
        $this->assertSame($before, $log->fresh()->processing_metadata?->toArray(), 'the composition the plan wrote was rolled back');

        // As after a media-version bump: the banked words are not this decode's, so nothing can be planned yet.
        config(['media-processing.service_structure.transcription_service' => 'local']);
        $this->assertSame('decode_pending', app(OutputEdgeCheck::class)->check($sermon->fresh())['status']);
    }

    #[Test]
    public function a_song_answer_cannot_extend_into_the_following_spoken_item(): void
    {
        $log = $this->service([], [['song', 10.0, 60.0], ['prayer', 60.0, 90.0]]);
        $song = $log->serviceSections()->where('section_type', ServiceSectionType::Song)->sole();
        $this->setEdge($song, 'end', 65.0);

        $plan = $this->plans()->forSection($song->fresh());

        $this->assertCount(1, CueSafeExtractionPlan::refusedOperatorEdges($plan['cue_edge_widening']));
        $this->assertContains(['kind' => 'operator_edge_refused', 'section_ids' => [$song->id]],
            app(PublicationPlanValidator::class)->validate($log, [$song], $plan['segments'], $plan['cue_edge_widening']));
    }

    #[Test]
    public function the_read_only_check_uses_the_songs_output_and_its_answer(): void
    {
        $log = $this->service([], [['song', 10.0, 60.0], ['prayer', 60.0, 90.0]]);
        $song = $log->serviceSections()->where('section_type', ServiceSectionType::Song)->sole();
        $this->setEdge($song, 'end', 55.0);
        $before = $log->fresh()->processing_metadata?->toArray();

        $check = app(OutputEdgeCheck::class)->check($song->fresh());

        $this->assertSame('checked', $check['status']);
        $this->assertSame(55.0, $check['edges']['end']['cut']);
        $this->assertSame(55.0, $check['edges']['end']['answer']);
        $this->assertSame($before, $log->fresh()->processing_metadata?->toArray());
    }

    private function plans(): CueSafeExtractionPlan
    {
        return app(CueSafeExtractionPlan::class);
    }

    private function setEdge(ServiceSection $section, string $edge, float $time): void
    {
        $metadata = $section->fresh()->metadata?->toArray() ?? [];
        $metadata[OutputEdgeAnswers::METADATA_KEY][$edge] = [
            'time' => $time, 'set_by_user_id' => 1, 'set_at' => now()->toIso8601String(), 'from_time' => (float) $section->{$edge.'_time'},
            'section_span' => ['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time], 'note' => '',
        ];
        $section->forceFill(['metadata' => ServiceSectionMetadata::fromArray($metadata)])->save();
    }

    /** @return array{MediaProcessingLog, ServiceSection} */
    private function sermonBesideTheHymn(): array
    {
        $log = $this->service([
            ['start' => 4001.10, 'end' => 4004.60, 'text' => 'He is the bread that came down from heaven.'],
            ['start' => 4004.90, 'end' => 4006.80, 'text' => "Let's stand and sing."],
            ['start' => 4009.50, 'end' => 4016.20, 'text' => 'Immortal honours rest on Jesus\' head,'],
            ['start' => 4016.40, 'end' => 4022.80, 'text' => 'my God, my portion, and my living bread.'],
            ['start' => 4023.00, 'end' => 4028.60, 'text' => 'In him I live, upon him cast my care.'],
            ['start' => 4040.00, 'end' => 4046.00, 'text' => 'Immortal honours rest on Jesus\' head'],
        ], [['bible_reading', 2093.92, 2372.83], ['song', 2372.88, 2543.31], ['sermon', 2545.22, 4007.22], ['song', 4008.21, 4175.06]],
            timeline: [[3990, 4008, 0.01, 0.8], [4008, 4029, 0.4, 0.7], [4029, 4175, 0.8, 0.01]]);
        // K16: the answer keeps the verse read as the sermon's conclusion; its last line before the
        // music window, "…rest on Jesus' head,", is as far as the end can go.
        $answer = CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'sermon_last_line' => 1, 'ties_to_sermon' => true, 'video_last_line' => 5, 'reason' => 'test',
        ], JSON_THROW_ON_ERROR)]]]]);
        OpenAI::fake([$answer, $answer]);
        app(SermonSongIntroductionCheck::class)->prepare($log, [['start_time' => 2545.22, 'end_time' => 4007.22]]);
        $this->bank($log->fresh(), 4016.20, [
            ['start' => 4014.60, 'end' => 4014.90, 'word' => ' on'],
            ['start' => 4014.90, 'end' => 4015.40, 'word' => " Jesus'"],
            ['start' => 4015.50, 'end' => 4016.10, 'word' => ' head,'],
            ['start' => 4016.50, 'end' => 4016.70, 'word' => ' my'],
        ]);

        return [$log->fresh(), $log->serviceSections()->where('section_type', ServiceSectionType::Sermon->value)->firstOrFail()];
    }

    /** @return array{MediaProcessingLog, ServiceSection} */
    private function sermonEndingInsideALine(): array
    {
        $log = $this->service([
            ['start' => 3018.00, 'end' => 3022.40, 'text' => 'The King has come, and he will come again.'],
            ['start' => 3022.60, 'end' => 3027.80, 'text' => 'Amen. And so as we come to our final hymn, we sing'],
            ['start' => 3028.00, 'end' => 3034.00, 'text' => 'of the gospel told, the story of the cross.'],
        ], [['sermon', 1694.88, 3024.83], ['other', 3024.83, 3071.99, 'Introduction to “O I Have Heard The Gospel Told”'], ['song', 3072.08, 3183.16]],
            timeline: [[3000, 3070, 0.01, 0.8], [3070, 3190, 0.8, 0.01]]);
        $answer = CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'sermon_last_line' => 1, 'ties_to_sermon' => true, 'video_last_line' => 2, 'reason' => 'test',
        ], JSON_THROW_ON_ERROR)]]]]);
        OpenAI::fake([$answer, $answer]);
        app(SermonSongIntroductionCheck::class)->prepare($log, [['start_time' => 1694.88, 'end_time' => 3024.83]]);
        $this->bank($log->fresh(), 3024.83, [
            ['start' => 3021.60, 'end' => 3022.30, 'word' => ' again.'],
            ['start' => 3022.70, 'end' => 3023.20, 'word' => ' Amen.'],
            ['start' => 3023.30, 'end' => 3023.50, 'word' => ' And'],
            ['start' => 3023.50, 'end' => 3023.70, 'word' => ' so'],
            ['start' => 3023.70, 'end' => 3023.85, 'word' => ' as'],
            ['start' => 3023.85, 'end' => 3024.00, 'word' => ' we'],
            ['start' => 3024.00, 'end' => 3024.30, 'word' => ' come'],
            ['start' => 3024.30, 'end' => 3024.45, 'word' => ' to'],
            ['start' => 3024.45, 'end' => 3024.60, 'word' => ' our'],
            ['start' => 3024.60, 'end' => 3024.95, 'word' => ' final'],
            ['start' => 3024.95, 'end' => 3025.40, 'word' => ' hymn,'],
            ['start' => 3026.10, 'end' => 3026.40, 'word' => ' we'],
            ['start' => 3026.40, 'end' => 3027.70, 'word' => ' sing'],
        ]);

        return [$log->fresh(), $log->serviceSections()->where('section_type', ServiceSectionType::Sermon->value)->firstOrFail()];
    }

    /** @return array{MediaProcessingLog, ServiceSection} */
    private function sermonAfterADeadFeed(): array
    {
        $log = $this->service([['start' => 15.0, 'end' => 18.0, 'text' => 'Well, good morning everyone.']], [['sermon', 10.0, 40.0]]);
        config(['media-processing.segmentation.adaptive_thresholds.enabled' => false]);
        $lines = [];

        for ($tenth = 0; $tenth < 500; $tenth++) {
            $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $tenth / 10);
            $lines[] = 'lavfi.astats.Overall.RMS_level='.($tenth < 150 ? '-inf' : '-25');
        }

        $log->update(['rms_log_path' => 'service-transcripts/operator-edge.rms.log']);
        Storage::disk('local')->put($log->rms_log_path, implode("\n", $lines));

        return [$log->fresh(), $log->serviceSections()->firstOrFail()];
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{0: string, 1: float, 2: float, 3?: string}>  $sections
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float}>|null  $timeline
     */
    private function service(array $cues, array $sections, ?array $timeline = null): MediaProcessingLog
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000,
            'audio_timeline_path' => $timeline !== null ? 'service-transcripts/operator-edge.classes.json' : null]);
        if ($timeline !== null) {
            Storage::disk('local')->put('service-transcripts/operator-edge.classes.json', AudioTimelineFixture::json($timeline, 5000.0));
        }
        $log->putServiceTranscriptPath('temp/operator-edge.json');
        Storage::disk('local')->put('temp/operator-edge.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));

        foreach ($sections as $order => $section) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $log->id,
                'section_type' => ServiceSectionType::from($section[0]),
                'section_order' => $order + 1,
                'start_time' => $section[1],
                'end_time' => $section[2],
                'title' => $section[3] ?? ucfirst($section[0]),
            ]);
        }

        return $log->fresh();
    }

    /** @param list<array{start: float, end: float, word: string}> $words */
    private function bank(MediaProcessingLog $log, float $edge, array $words): void
    {
        $evidence = app(OutputEdgeWordTimings::class);
        $window = $evidence->window($evidence->cues($log), $edge, $log->duration);
        $this->assertNotNull($window);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($evidence->identity($log, $window)), [
            'identity' => $evidence->identity($log, $window), 'words' => $words, 'compute_seconds' => 1.0,
        ]);
        $log->refresh();
    }
}
