<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Actions\ServiceReview\SetOutputEdge;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\ServiceSection;
use App\Models\User;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\SermonSongIntroductionCheck;
use App\Services\ChurchService\SpokenEdgeSentenceCheck;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Sermon\SermonExtractionPlanResolver;
use App\Support\MediaProcessingVersion;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\RuledEndingsTestCase;

/**
 * Operator-ruled endings as a pipeline regression set (plan §4.5, 2026-10-14).
 *
 * Each fixture in tests/Fixtures/RuledEndings is one ending the operator ruled by ear in the
 * 2026-10 endings listening (storage/scratch/endings-listening/answer-key.json), captured from
 * a settled run: its sections, transcript, audio timeline, sound levels around the section edges
 * (with the threshold the whole recording gives), and the banked edge words and sentence answers.
 * The cut planner is shared by weekly and historic livestream runs, so a fix that moves a ruled
 * edge fails here whichever path it was meant for.
 *
 * Two checks per fixture:
 *  - fidelity: rebuilt from the fixture, the planner gives the cut it gave on the live data at
 *    capture (`live`), so the fixture holds everything the planner reads;
 *  - the ruling, by what it expects (tests/Fixtures/Rulings/edge-rulings.json, plan §4.6): `keep`
 *    stays within 0.31 s of the cut the operator heard; `outward` opens earlier or ends later than
 *    it; `inward` opens later or ends earlier; `at` is within a second of the time in the note.
 *    The first fixtures predate that set and carry only an ending's answer (right or clips_word).
 *    A `guard-` fixture is no ruling: it keeps an edge a gate found a candidate rule moving
 *    wrongly at the cut it had, and its ruling's source says it was not listened to.
 *
 * A run fixture (`run-<run>-<date>`, scripts/ruled-endings/merge.py) holds one run's data once and
 * a `rulings` list; the eight RuledEndingsShard tests check all entries after one rebuild. The 10-22 set is the CI ratchet:
 * every captured ruling current code met (plan §4.6), against the newest rulings by its capture date.
 *
 * A fixture whose capture predates a deliberate change may be re-captured with
 * scripts/ruled-endings/capture.php (sample in manifest.json); the ruling check is what must keep passing.
 */
class RuledEndingsRegressionTest extends RuledEndingsTestCase
{
    #[Test]
    #[TestWith(['run-1296-2026-10-25-edges', 3779, 215.48])]
    #[TestWith(['run-1268-2026-10-23', 4735, 1856.36])]
    #[TestWith(['run-1342-2026-10-25', 4322, 1569.55])]
    public function a_song_start_inside_an_announcement_moves_after_its_sentence(string $name, int $sectionId, float $expected): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(base_path('tests/Fixtures/RuledEndings/'.$name.'.json.gz'))), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        $section = ServiceSection::query()->findOrFail($sectionId);
        $evidence = app(OutputEdgeWordTimings::class);
        $window = $evidence->window($evidence->cues($log), (float) $section->start_time, $log->duration, $log);
        $this->assertNotNull($window);
        $identity = $evidence->identity($log, $window);
        // Older captures can differ by a floating-point epsilon in their window identity.
        // Rebank the same recorded words against the window this fixture now computes.
        foreach ($fixture['files'] as $file) {
            $payload = json_decode($file['content'], true, flags: JSON_THROW_ON_ERROR);
            if (isset($payload['words'], $payload['identity']['start'], $payload['identity']['end'])
                && MediaProcessingVersion::matches($payload['identity']['media_processing'] ?? null)
                && ($payload['identity']['model'] ?? null) === $identity['model']
                && ($payload['identity']['window_rule'] ?? null) === ($identity['window_rule'] ?? null)
                && abs($payload['identity']['start'] - $window['start']) < 0.00001
                && abs($payload['identity']['end'] - $window['end']) < 0.00001) {
                app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), [...$payload, 'identity' => $identity]);
                break;
            }
        }

        $plan = app(CueSafeExtractionPlan::class)->forSection($section->fresh());

        $this->assertEqualsWithDelta($expected, $plan['segments'][0]['start_time'], 0.001);
    }

    /** Heard right on 10-26: sentence-listening-20261026:174158-start. */
    #[Test]
    public function wide_text_keeps_an_existing_complete_sentence_cut_instead_of_an_earlier_stop(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(base_path('tests/Fixtures/RuledEndings/run-1049-2026-10-23.json.gz'))), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        $plan = app(CueSafeExtractionPlan::class)->forSection($log->serviceSections()->findOrFail(174158));
        $this->assertEqualsWithDelta(3866.22, $plan['segments'][0]['start_time'], self::RIGHT_TOLERANCE);
    }

    #[Test]
    public function a_song_ends_before_an_untranscribed_introduction_to_the_next_song(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-song-changes-20261020-1297-s3803-end-current.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);

        $section = $log->serviceSections()->findOrFail(3803);
        $admin = User::factory()->create(['is_admin' => true]);
        Bus::fake([PrepareSectionPublicationCandidates::class]);
        app(SetOutputEdge::class)->execute($section, 'end', '28:26', $admin->id,
            'Operator ruling: before the next song introduction.');

        $plan = app(CueSafeExtractionPlan::class)->forSection($section->fresh());

        $this->assertEqualsWithDelta(1706.0, end($plan['segments'])['end_time'], self::FIDELITY_TOLERANCE, json_encode($plan['cue_edge_widening'], JSON_THROW_ON_ERROR));
    }

    /**
     * 1012, 1088 and 946 end at the silence after the band stops: B or either on the Song End Silence page (10-28).
     * 946's "Well, let's pray again." opens over the last music, so it ends there, faded (register E24): its
     * earlier either point, 378.52.
     */
    #[Test]
    #[TestWith(['listening-held-word-20261021-1211-s175128-end', 175128, 4387.835])]
    #[TestWith(['canary13-041-1250-end', 3124, 421.82])]
    #[TestWith(['listening-held-word-20261021-1012-s1291-end', 1291, 997.466])]
    #[TestWith(['listening-held-word-20261021-1023-s1364-end', 1364, 2500.34])]
    #[TestWith(['listening-held-word-20261021-1088-s1778-end', 1778, 545.327])]
    #[TestWith(['listening-held-word-20261021-946-s780-end', 780, 378.52])]
    #[TestWith(['listening-held-word-20261021-1213-s175130-end', 175130, 4330.48])]
    public function song_ends_distinguish_held_words_from_the_following_speech(string $name, int $sectionId, float $expected): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/'.$name.'.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);

        $plan = app(CueSafeExtractionPlan::class)->forSection($log->serviceSections()->findOrFail($sectionId));

        $this->assertEqualsWithDelta($expected, end($plan['segments'])['end_time'], self::RIGHT_TOLERANCE, json_encode($plan['cue_edge_widening'], JSON_THROW_ON_ERROR));
    }

    /** Both songs need RMS contexts for their exact decode identities, including the next song's end. */
    #[Test]
    #[TestWith(['run-1154-2026-10-23', 3753, 3754])]
    #[TestWith(['run-1215-2026-10-23', 2639, 2640])]
    #[TestWith(['run-1237-2026-10-23', 2948, 2949])]
    #[TestWith(['run-1267-2026-10-23', 3347, 3348])]
    #[TestWith(['run-1267-2026-10-23', 3348, 3349])]
    #[TestWith(['run-1267-2026-10-23', 3349, 3350])]
    #[TestWith(['run-1307-2026-10-23', 4652, 4653])]
    public function the_next_song_starts_at_the_previous_songs_change_dip(string $name, int $beforeId, int $afterId): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/'.$name.'.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        $planner = app(CueSafeExtractionPlan::class);

        $before = $planner->forSection($log->serviceSections()->findOrFail($beforeId));
        $after = $planner->forSection($log->serviceSections()->findOrFail($afterId));

        $this->assertContains('song_change_dip', array_column($before['cue_edge_widening'], 'reason'));
        $this->assertEqualsWithDelta(end($before['segments'])['end_time'], $after['segments'][0]['start_time'], self::FIDELITY_TOLERANCE);
    }

    /**
     * 1272: "Bless you." is said over the last of "Father God I Wonder" (heard 10-30: the song ends
     * there, faded, register E24), and "Great Is Thy Faithfulness" opens at the dip after it (heard
     * 10-21: 885.5). The dip still marks where the next song's introduction starts; without it the
     * song's own start rule took its first line.
     */
    #[Test]
    public function the_next_song_starts_at_the_dip_after_speech_over_the_previous_songs_music(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/run-1272-2026-10-23.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        $planner = app(CueSafeExtractionPlan::class);

        $before = $planner->forSection($log->serviceSections()->findOrFail(3403));
        $after = $planner->forSection($log->serviceSections()->findOrFail(3404));

        $this->assertEqualsWithDelta(882.98, end($before['segments'])['end_time'], self::FIDELITY_TOLERANCE);
        $this->assertSame(CueSafeExtractionPlan::SONG_END_SPEECH_OVER_MUSIC, collect($before['cue_edge_widening'])->last(static fn (array $entry): bool => $entry['edge'] === 'end')['reason']);
        $this->assertEqualsWithDelta(885.5, $after['segments'][0]['start_time'], self::FIDELITY_TOLERANCE);
        $this->assertContains('song_change_dip', array_column($after['cue_edge_widening'], 'reason'));
    }

    /**
     * Speech over a song's last music ends the song at its line (register E24) only where music is
     * playing there or in the window before (10-31). 992's recording ends before "Restore, O Lord" is
     * sung: its section is the leader reading the words and "Let's stand and sing this one through
     * together." with no music anywhere, and its one-word line "together." ends that sentence, so the
     * song keeps its end after it. In 971, 1259 and 1363 the music stops as a window ends and the
     * speech opens the next: they still end at the speech's line.
     */
    #[Test]
    #[TestWith(['guard-20261031-992-s163678-end', 163678, 1580.74, 'song_end_silence'])]
    #[TestWith(['guard-20261031-971-s1086-end', 1086, 605.00, CueSafeExtractionPlan::SONG_END_SPEECH_OVER_MUSIC])]
    #[TestWith(['guard-20261031-1259-s3243-end', 3243, 4370.80, CueSafeExtractionPlan::SONG_END_SPEECH_OVER_MUSIC])]
    #[TestWith(['guard-20261031-1363-s215989-end', 215989, 3275.11, CueSafeExtractionPlan::SONG_END_SPEECH_OVER_MUSIC])]
    public function speech_ends_a_song_at_its_line_only_over_music(string $name, int $sectionId, float $expected, string $reason): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3)."/Fixtures/RuledEndings/{$name}.json.gz")), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);

        $plan = app(CueSafeExtractionPlan::class)->forSection($log->serviceSections()->findOrFail($sectionId));

        $this->assertEqualsWithDelta($expected, end($plan['segments'])['end_time'], self::FIDELITY_TOLERANCE);
        $this->assertSame($reason, collect($plan['cue_edge_widening'])->last(static fn (array $entry): bool => $entry['edge'] === 'end')['reason']);
    }

    #[Test]
    public function a_next_song_does_not_use_a_dip_when_the_previous_song_ends_at_speech(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/run-1215-2026-10-23.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        $planner = \Mockery::mock(CueSafeExtractionPlan::class)->makePartial();
        $planner->shouldReceive('forSection')->withArgs(static fn (ServiceSection $section): bool => $section->id === 2639)
            ->andReturn(['segments' => [['start_time' => 1400.0, 'end_time' => 1578.0]],
                'cue_edge_widening' => [['edge' => 'end', 'reason' => 'song_end_speech', 'time' => 1578.0]], 'audio_fade_out' => null]);

        $plan = $planner->forSection($log->serviceSections()->findOrFail(2640));

        $this->assertEqualsWithDelta(1584.55, $plan['segments'][0]['start_time'], self::FIDELITY_TOLERANCE);
    }

    /**
     * The gate's false candidate (10-21): a dip at 561.5 s sits before 977 §1005, but
     * §232943 ends at the speech between them, so §1005 keeps its own start.
     */
    #[Test]
    public function a_next_song_keeps_its_start_when_the_previous_song_ended_at_speech(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/guard-20261021-977-s1005-start.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        $planner = app(CueSafeExtractionPlan::class);

        $before = $planner->forSection($log->serviceSections()->findOrFail(232943));
        $after = $planner->forSection($log->serviceSections()->findOrFail(1005));

        $this->assertNotContains('song_change_dip', array_column($before['cue_edge_widening'], 'reason'));
        $this->assertNotContains('song_change_dip', array_column($after['cue_edge_widening'], 'reason'));
        $this->assertEqualsWithDelta(589.994, $after['segments'][0]['start_time'], self::FIDELITY_TOLERANCE);
    }

    #[Test]
    public function the_separate_1262_start_discrepancy_is_not_moved_by_the_song_change_rule(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-songs-20261020-1262-s3284-end-current.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);

        $plan = app(CueSafeExtractionPlan::class)->forSection($log->serviceSections()->findOrFail(3284));

        // The plan once recorded 1531 s as heard but 1520.86 s as the cut (plan §4.0, 10-20). The operator
        // settled it on 10-24 (Intro Rule page): 1520, the introduction and nothing else. This rule must not move it.
        $this->assertEqualsWithDelta(1520.0, $plan['segments'][0]['start_time'], self::FIDELITY_TOLERANCE);
        $this->assertSame('operator_set_edge', $plan['cue_edge_widening'][0]['reason']);
    }

    #[Test]
    public function a_held_word_over_resumed_music_does_not_end_a_song_early(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-songs-20261020-1212-s2910-end-current.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);

        $plan = app(CueSafeExtractionPlan::class)->forSection($log->serviceSections()->findOrFail(2910));

        $cut = end($plan['segments'])['end_time'];

        $this->assertGreaterThan(4532.28, $cut, 'A gap before the smeared “Lord” cannot cut the sung sound that follows it.');
        $this->assertLessThanOrEqual(4537.18, $cut, 'The repair must still stop before the following prayer.');
    }

    #[Test]
    public function a_k16_sermon_end_beside_a_song_is_left_to_the_song_rule(): void
    {
        // Withdrawn 10-19: asking here moved 11 and held 24 of 232 edges ruled right, and made
        // 1115 itself worse (storage/scratch/edge-gate-20261019).
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-edges-20261018-1115-end.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        OpenAI::fake(array_fill(0, 40, CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'decision' => 'extend', 'words_to_move' => 'stand and sing it together this evening.',
            'reason' => 'The invitation completes the sentence already included.',
        ], JSON_THROW_ON_ERROR)]]]])));

        app(SpokenEdgeSentenceCheck::class)->prepare($log, $log->processing_metadata->raw['sermon_composition']['selected_section_ids']);
        $plan = app(SermonExtractionPlanResolver::class)->resolve($log->fresh());

        $this->assertFalse(array_any($this->askedEdges($log, 'end'), static fn (float $time): bool => abs($time - 1490.74) < 0.01), 'the K16 end is not a sentence question');
        $this->assertEqualsWithDelta($fixture['live']['cut'], end($plan['segments'])['end_time'], self::FIDELITY_TOLERANCE);
    }

    /**
     * Withdrawn 10-19 with the K16 question: an end with no next section and an opening without
     * a spoken neighbour (928's openings, 1053's) were asked too. Only an opening the dead-feed
     * rule exposes is asked without one (957).
     */
    #[Test]
    #[TestWith(['listening-edges-20261018-928-end'])]
    #[TestWith(['listening-edges2-20261018-1053-end'])]
    #[TestWith(['listening-edges-20261018-1115-end'])]
    public function only_edges_between_two_spoken_items_are_asked(string $name): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/'.$name.'.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        OpenAI::fake(array_fill(0, 40, CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'decision' => 'keep', 'words_to_move' => '', 'reason' => 'A test response, not an operator ruling.',
        ], JSON_THROW_ON_ERROR)]]]])));
        $check = app(SpokenEdgeSentenceCheck::class);

        $selected = $log->processing_metadata->raw['sermon_composition']['selected_section_ids'];
        $banked = $this->askedEdges($log);
        $summary = $check->prepare($log, $selected);
        $log->refresh();
        $deadFeedOpenings = array_column(array_filter($check->outputEdges($log, $log->serviceSections()->orderBy('start_time')->get()->all(), $selected),
            static fn (array $cut): bool => ($cut[2] ?? null) === 'dead_feed_opening'), 1);

        $asked = array_diff_key($this->askedEdges($log), $banked);
        $this->assertCount($summary['asked'], $asked);
        foreach ($asked as $time) {
            $this->assertTrue($check->spokenSides($log, $time) !== null || array_any($deadFeedOpenings, static fn (float $opening): bool => abs($opening - $time) < 0.001),
                "{$name}: the edge at {$time}s has no spoken neighbour and is no dead-feed opening");
        }
    }

    #[Test]
    public function conflicting_sentence_answers_never_hold_a_k16_end(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-edges-20261018-1115-end.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        OpenAI::fake(array_map(static fn (string $decision): CreateResponse => CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'decision' => $decision, 'words_to_move' => 'stand and sing it together this evening.', 'reason' => 'Disputed thought.',
        ], JSON_THROW_ON_ERROR)]]]]), array_merge(...array_fill(0, 20, ['extend', 'shrink']))));
        app(SpokenEdgeSentenceCheck::class)->prepare($log, $log->processing_metadata->raw['sermon_composition']['selected_section_ids']);

        $plan = app(SermonExtractionPlanResolver::class)->resolve($log->fresh());

        $this->assertNotSame('sermon_edge_unresolved', $plan['metadata']['reason']);
        $this->assertEqualsWithDelta($fixture['live']['cut'], end($plan['segments'])['end_time'], self::FIDELITY_TOLERANCE);
    }

    /** Early cue starts must not undo the last word's decoded placement and tail. */
    #[Test]
    #[TestWith([932, 2383.615])]
    #[TestWith([1371, 1428.54])]
    public function an_early_next_cue_does_not_clip_the_sermons_last_word(int $run, float $expected): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(base_path('tests/Fixtures/RuledEndings/run-'.$run.'-2026-10-23.json.gz'))), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);

        $plan = app(SermonExtractionPlanResolver::class)->resolve($log);
        $audit = json_encode($plan['metadata']['cue_edge_widening'] ?? [], JSON_THROW_ON_ERROR);

        $this->assertEqualsWithDelta($expected, end($plan['segments'])['end_time'], self::FIDELITY_TOLERANCE, $audit);
    }

    #[Test]
    #[TestWith([1270, 4280.22])]
    #[TestWith([1273, 4029.06])]
    #[TestWith([1135, 3722.2])]
    public function the_onset_cap_does_not_protect_a_later_lines_word(int $run, float $expected): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(base_path('tests/Fixtures/RuledEndings/run-'.$run.'-2026-10-23.json.gz'))), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);

        $plan = app(SermonExtractionPlanResolver::class)->resolve($log);

        $this->assertEqualsWithDelta($expected, end($plan['segments'])['end_time'], self::FIDELITY_TOLERANCE);
    }

    /**
     * 1108's sermon ends on "…To make a wretch is true." (4119.46) before the housekeeping "Let's
     * stand to sing." (4119.58–4121.44). The recording drops out mid-word (levels at −∞ from
     * 4119.1), and the end was cut inside "Let" (4119.76; "ends too early … might be an audio
     * quality issue", edges 10-18).
     */
    #[Test]
    public function a_dropout_inside_a_word_is_not_a_pause_to_end_in(): void
    {
        $this->mock(SermonSongIntroductionCheck::class, fn (MockInterface $mock) => $mock->shouldReceive('join')->andReturn(null));
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/run-1108-2026-10-23.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);

        // Exercise the dropout edge itself; the refreshed fixture's section now ends after the announcement.
        $plan = app(CueSafeExtractionPlan::class)->forSpans($log, [['start_time' => 2554.53, 'end_time' => 4119.58]], sermonEnd: true);
        $cut = end($plan['segments'])['end_time'];
        $audit = json_encode(array_values(array_filter($plan['cue_edge_widening'], static fn (array $edge): bool => $edge['edge'] === 'end')), JSON_THROW_ON_ERROR);

        $this->assertGreaterThanOrEqual(4119.46, $cut, $audit);
        $this->assertLessThanOrEqual(4119.58, $cut, 'not inside "Let": '.$audit);
    }

    /**
     * 1070's sermon ends on the preacher's "Amen." (decoded 1628.78). Another decode heard that same
     * Amen at 1629.07, and the end was cut there as if it were the next word ("B clips the preachers
     * amen", operator 10-30; A, 1630.41 before "Let's stand", is right). The Amen sounds 1628.7–1629.35
     * and silence follows to 1630.3, so both decodes heard one word. A heard word with the last kept
     * word's text that starts just after it is that word, not the next one: the end falls in that silence.
     */
    #[Test]
    public function another_decodes_copy_of_the_last_word_is_not_the_next_word(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(base_path('tests/Fixtures/RuledEndings/run-1070-2026-10-23.json.gz'))), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);

        $plan = app(SermonExtractionPlanResolver::class)->resolve($log);
        $audit = json_encode(array_values(array_filter($plan['metadata']['cue_edge_widening'] ?? [], static fn (array $edge): bool => $edge['edge'] === 'end')), JSON_THROW_ON_ERROR);

        $cut = end($plan['segments'])['end_time'];
        $this->assertGreaterThanOrEqual(1629.4, $cut, 'after the Amen\'s sound: '.$audit);
        $this->assertLessThanOrEqual(1630.41, $cut, 'before "Let\'s": '.$audit);
    }

    /** 1133's congregation answers the preacher's Amen (to 4000.54) with its own from 4000.73: the next word, not a copy. */
    #[Test]
    public function the_congregations_amen_after_a_gap_is_the_next_word(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(base_path('tests/Fixtures/RuledEndings/run-1133-2026-10-23.json.gz'))), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);

        $plan = app(SermonExtractionPlanResolver::class)->resolve($log);

        $this->assertEqualsWithDelta(4000.73, end($plan['segments'])['end_time'], self::FIDELITY_TOLERANCE);
    }

    /**
     * 1288's sermon ended where another decode put the start of "So let's sing…" (3755.31), but the
     * sound of "So" is up from 3755.26 after a quiet at 3755.19 (−53 dB under −25 dB): the end
     * carried the onset ("It's not the second Amen that's the problem", operator 10-29). It ends in
     * that quiet, after the congregation's Amen (heard 10-29 with 928, 1340 and 1303: "significantly
     * better"). 1202's end also sits on the next word's decoded start, but its words run together
     * (a 1.5 dB dip), so there is no quiet to end in and it stays where it was heard right.
     */
    #[Test]
    #[TestWith(['run-1288-2026-10-28-amen', 3755.19, true])]
    #[TestWith(['run-1202-2026-10-23', 1600.66, false])]
    public function an_end_on_the_next_words_onset_moves_into_the_quiet_before_its_sound(string $capture, float $expected, bool $moves): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(base_path("tests/Fixtures/RuledEndings/{$capture}.json.gz"))), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);

        $plan = app(SermonExtractionPlanResolver::class)->resolve($log);
        $ends = array_values(array_filter($plan['metadata']['cue_edge_widening'], static fn (array $edge): bool => $edge['edge'] === 'end'));
        $audit = json_encode(end($ends), JSON_THROW_ON_ERROR);

        $this->assertEqualsWithDelta($expected, end($plan['segments'])['end_time'], self::FIDELITY_TOLERANCE, $audit);
        $this->assertSame($moves, isset(end($ends)['before_next_word_sound']), $audit);
    }

    #[Test]
    public function a_smeared_prayer_opening_does_not_pull_a_song_end_into_its_outro(): void
    {
        // Heard blind 10-20: 3833.43 s is right; bounding the song at the smeared "Our" (3830.46 s)
        // cut the song, as it did 12 of the 20 song ends that rule moved.
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-edges3-20261018-954-s901-end.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        $plan = app(CueSafeExtractionPlan::class)->forSection($log->serviceSections()->findOrFail(901));

        $this->assertEqualsWithDelta(3833.43, end($plan['segments'])['end_time'], self::RIGHT_TOLERANCE, json_encode($plan['cue_edge_widening'], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_reading_after_a_song_does_not_use_its_last_lyric_as_the_speech_onset(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-last-20261019-1291-start.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);

        $edges = app(CueSafeExtractionPlan::class)->songEndSpeechEdges($log, [['start_time' => 950.494, 'end_time' => 1115.54]]);

        $this->assertSame([1119.86], $edges, 'decode the reading cue, rather than anchoring it on the preceding sung lyric');
    }

    #[Test]
    public function an_opening_mic_check_can_be_removed_without_losing_the_sermon_introduction(): void
    {
        $this->mock(SermonSongIntroductionCheck::class, fn (MockInterface $mock) => $mock->shouldReceive('join')->andReturn(null));
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-edges-20261018-957-start.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($this->withoutSentenceAnswers($fixture));
        OpenAI::fake(array_fill(0, 20, CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'decision' => 'shrink', 'words_to_move' => 'Is that a bit better?', 'reason' => 'The microphone check precedes the introduction.',
        ], JSON_THROW_ON_ERROR)]]]])));
        $summary = app(SpokenEdgeSentenceCheck::class)->prepare($log, $log->processing_metadata->raw['sermon_composition']['selected_section_ids']);
        $plan = app(SermonExtractionPlanResolver::class)->resolve($log->fresh());

        $this->assertGreaterThan(0, $summary['agreed_moves']);
        $this->assertGreaterThanOrEqual(30.98, $plan['segments'][0]['start_time']);
        $this->assertLessThanOrEqual(32.0, $plan['segments'][0]['start_time'], 'keep "Let me begin"');
        OpenAI::assertSent(Chat::class, static fn (string $method, array $parameters): bool => str_contains($parameters['messages'][0]['content'], 'An opening check also removes complete microphone checks'));
    }

    #[Test]
    public function an_opening_the_recording_joins_mid_sentence_keeps_that_sentence(): void
    {
        $this->mock(SermonSongIntroductionCheck::class, fn (MockInterface $mock) => $mock->shouldReceive('join')->andReturn(null));
        // 1269's sermon: the feed came back during "…of a little boy who went to church with his
        // mother", and both answers shrank it away as a fragment (operator, 10-22: words lost).
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-edges-20261018-957-start.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($this->withoutSentenceAnswers($fixture));
        OpenAI::fake(array_fill(0, 20, CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'decision' => 'keep', 'words_to_move' => '', 'reason' => 'The cut is right.',
        ], JSON_THROW_ON_ERROR)]]]])));

        app(SpokenEdgeSentenceCheck::class)->prepare($log, $log->processing_metadata->raw['sermon_composition']['selected_section_ids']);

        OpenAI::assertSent(Chat::class, static fn (string $method, array $parameters): bool => str_contains($parameters['messages'][0]['content'], 'A first sentence the recording joins partway through is the item\'s own words, not a split thought: keep it.')
            && str_contains($parameters['messages'][1]['content'], 'The recording may join its first sentence partway through: those words are still its own, so keep them.'));
    }
}
