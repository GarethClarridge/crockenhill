<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\HoldSectionForContentReview;
use App\Data\ChurchServiceTranscript;
use App\Enums\ChurchServiceItemSource;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Sermon\SermonExtractionPlanResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonExtractionPlanResolverTest extends TestCase
{
    use DatabaseTransactions;

    private SermonExtractionPlanResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(SermonExtractionPlanResolver::class);
    }

    #[Test]
    public function composition_does_not_clear_an_upstream_material_boundary_review(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 400]);
        $sermon = ServiceSection::factory()->create(['media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon, 'start_time' => 100, 'end_time' => 300,
            'needs_manual_review' => true, 'metadata' => ['review_flags' => [ServiceStructureValidator::FLAG_SERMON_BOUNDARY_MATERIAL_RISK]]]);

        $plan = app(SermonExtractionPlanResolver::class)->resolve($log);

        $this->assertContains(ServiceStructureValidator::FLAG_SERMON_BOUNDARY_MATERIAL_RISK, $sermon->fresh()->metadata->reviewFlags);
        $this->assertTrue($plan['metadata']['requires_review']);
    }

    #[Test]
    public function it_cuts_only_named_sections_without_absorbing_gaps_or_using_baseline_times(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000, 'sermon_start_time' => 0, 'sermon_end_time' => 4000]);
        $reading = ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => 'bible_reading', 'start_time' => 100, 'end_time' => 200, 'needs_manual_review' => false, 'metadata' => ['reading_reference' => 'Jn 3:1-16']]);
        $sermon = ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => 'sermon', 'start_time' => 220, 'end_time' => 3200, 'confidence' => 0.4, 'needs_manual_review' => false, 'metadata' => ['sermon_reference' => 'John 3:1-16']]);
        $prayer = ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => 'prayer', 'start_time' => 3210, 'end_time' => 3250, 'needs_manual_review' => false]);
        ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => 'song', 'start_time' => 3280, 'end_time' => 3500]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame([[100.0, 200.0], [220.0, 3200.0], [3210.0, 3250.0]], array_map(fn (array $span): array => [$span['start_time'], $span['end_time']], $plan['segments']));
        $this->assertSame([$reading->id, $sermon->id, $prayer->id], $plan['metadata']['selected_section_ids']);
    }

    #[Test]
    public function absent_accepted_sections_never_fall_back_to_the_old_detector(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['sermon_start_time' => 0, 'sermon_end_time' => 3000]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame([], $plan['segments']);
        $this->assertTrue($plan['metadata']['requires_review']);
    }

    #[Test]
    public function duplicate_matching_readings_and_multiple_prayers_require_membership_review(): void
    {
        $log = $this->logWithSermon(500.0, 1200.0);
        $sermon = $log->serviceSections()->sole();
        $sermon->update(['metadata' => ['sermon_reference' => 'John 3:1-16']]);
        $this->reading($log, 3, 100, 200, reference: 'John 3:1-16');
        $this->reading($log, 4, 300, 400, reference: 'Jn 3:1-16');
        $this->section($log, ServiceSectionType::Prayer, 5, 1210, 1250);
        $this->section($log, ServiceSectionType::Prayer, 6, 1260, 1300);

        $plan = $this->resolver->resolve($log);
        $this->assertTrue($plan['metadata']['requires_review']);
        $this->assertSame(['sermon_reading_membership_unresolved', 'sermon_prayer_membership_unresolved'], array_column($plan['metadata']['risks'], 'kind'));
    }

    #[Test]
    public function a_reviewed_selection_survives_recomposition_but_not_changed_section_bounds(): void
    {
        $log = $this->logWithSermon(500.0, 1200.0);
        $sermon = $log->serviceSections()->sole();
        $reading = $this->reading($log, 3, 100, 200);
        $composition = $this->resolver->compose($log);
        $this->resolver->reviewComposition($log, [$reading->id, $sermon->id], $composition['input_identity'], 1);
        $plan = $this->resolver->resolve($log->fresh());
        $this->assertSame([$reading->id, $sermon->id], $plan['metadata']['selected_section_ids']);
        $this->assertFalse($plan['metadata']['requires_review']);

        $reading->update(['end_time' => 210, 'duration' => 110]);
        $changed = $this->resolver->resolve($log->fresh());
        $this->assertTrue($changed['metadata']['requires_review']);
        $this->assertNotSame($composition['input_identity'], $changed['metadata']['input_identity']);
    }

    #[Test]
    public function selected_sections_with_overlapping_bounds_are_rejected_without_a_fallback(): void
    {
        $log = $this->logWithSermon(500.0, 1200.0);
        $sermon = $log->serviceSections()->sole();
        $this->section($log, ServiceSectionType::Other, 3, 1100, 1400)->update(['metadata' => ['sermon_continuation' => ['of_section_id' => $sermon->id, 'evidence' => 'Same sermon continues', 'source' => 'review']]]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('overlapping');
        $this->resolver->resolve($log);
    }

    #[Test]
    public function a_media_version_change_invalidates_a_section_signature(): void
    {
        $section = ServiceSection::factory()->create();
        $before = $section->mediaSignature();
        config(['media-processing.media_processing_version' => (int) config('media-processing.media_processing_version') + 1]);
        $this->assertNotSame($before, $section->mediaSignature());
    }

    #[Test]
    public function uncovered_timed_speech_requires_review_but_silence_and_identified_content_do_not(): void
    {
        Storage::fake('local');
        config(['media-processing.storage.temp_disk' => 'local']);
        $log = $this->logWithSermon(100, 200);
        $log->update(['duration' => 300, 'audio_timeline_path' => 'temp/timeline.json', 'processing_metadata' => ['service_transcript_path' => 'temp/transcript.json']]);
        $this->section($log, ServiceSectionType::Song, 3, 240, 270);
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 100, 'end' => 200, 'text' => 'Identified sermon.'],
            ['start' => 205, 'end' => 210, 'text' => 'Words outside the sermon.'],
            ['start' => 245, 'end' => 260, 'text' => 'Identified singing.'],
        ], 300, ChurchServiceTranscript::SOURCE_MOCK);
        Storage::disk('local')->put('temp/transcript.json', json_encode($transcript->toArray(), JSON_THROW_ON_ERROR));
        $timeline = ['model' => 'test', 'model_revision' => '1', 'window_seconds' => 300, 'audio_seconds' => 300,
            'windows' => [['start' => 0, 'end' => 300, 'music' => 0, 'speech' => 0.9]]];
        Storage::disk('local')->put('temp/timeline.json', json_encode($timeline, JSON_THROW_ON_ERROR));
        $composition = $this->resolver->compose($log);
        $this->assertSame([], $composition['risks']);
        $this->assertFalse($composition['requires_review']);
        $this->assertCount(1, $this->resolver->resolve($log)['segments']);

        $sermon = $log->serviceSections()->where('section_type', ServiceSectionType::Sermon)->sole();
        $sermon->update(['metadata' => ['sermon_reference' => 'John 3:16']]);
        $reading = $this->section($log, ServiceSectionType::BibleReading, 1, 40, 60);
        $reading->update(['metadata' => ['reading_reference' => 'John 3:16']]);
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 30, 'end' => 35, 'text' => 'Speech before the selected reading.'],
            ['start' => 45, 'end' => 55, 'text' => 'Identified reading.'],
            ['start' => 70, 'end' => 75, 'text' => 'Speech between selected sections.'],
            ['start' => 100, 'end' => 200, 'text' => 'Identified sermon.'],
            ['start' => 205, 'end' => 210, 'text' => 'Speech after the selected sermon.'],
            ['start' => 245, 'end' => 260, 'text' => 'Identified singing.'],
        ], 300, ChurchServiceTranscript::SOURCE_MOCK);
        Storage::disk('local')->put('temp/transcript.json', json_encode($transcript->toArray(), JSON_THROW_ON_ERROR));
        $composition = $this->resolver->compose($log);
        $this->assertSame([], $composition['risks']);
        $this->assertFalse($composition['requires_review']);
        $this->assertCount(2, $this->resolver->resolve($log)['segments']);

        $continuation = $this->section($log, ServiceSectionType::Other, 4, 215, 230);
        $continuation->update(['metadata' => ['sermon_continuation' => ['of_section_id' => $sermon->id, 'evidence' => 'Same sermon resumes', 'source' => 'review']]]);
        $composition = $this->resolver->compose($log);
        $this->assertSame(['sermon_uncovered_speech'], array_column($composition['risks'], 'kind'));
        $this->assertTrue($composition['requires_review']);
        $this->assertStringContainsString('205.000–210.000s', $composition['risks'][0]['detail']);

        $this->section($log, ServiceSectionType::Prayer, 2, 200, 215);
        $this->assertFalse($this->resolver->compose($log)['requires_review']);
        $log->serviceSections()->where('section_type', ServiceSectionType::Prayer)->delete();

        $timeline['windows'][0]['speech'] = 0.1;
        Storage::disk('local')->put('temp/timeline.json', json_encode($timeline, JSON_THROW_ON_ERROR));
        $this->assertFalse($this->resolver->compose($log)['requires_review']);
    }

    #[Test]
    public function a_review_cannot_include_a_prayer_after_the_post_sermon_song(): void
    {
        $log = $this->logWithSermon(100, 200);
        $sermon = $log->serviceSections()->sole();
        $this->section($log, ServiceSectionType::Song, 3, 220, 250);
        $prayer = $this->section($log, ServiceSectionType::Prayer, 4, 260, 270);
        $composition = $this->resolver->compose($log);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('before the post-sermon song');
        $this->resolver->reviewComposition($log, [$sermon->id, $prayer->id], $composition['input_identity'], 1);
    }

    /**
     * §1301 on run 1014: "Lo He Comes With Clouds Descending", 290 s of congregational singing
     * typed `other`, sitting between the sermon section and the closing prayer. Because `other`
     * is a trailing type the span absorbs it, so the published sermon media (0–1501 s) contains
     * the hymn — the harm this risk exists to surface.
     *
     * The structure stage says which sections read as sung; only here is it known that the
     * sermon's span swallowed one, because the span does not exist until the plan is resolved.
     */
    #[Test]
    public function it_excludes_an_unselected_sung_item_from_the_sermon(): void
    {
        $log = $this->runWithAbsorbedSection([ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG]);

        $plan = $this->resolver->resolve($log);

        $this->assertCount(1, $plan['segments']);
        $this->assertSame(1157.0, $plan['segments'][0]['end_time']);

    }

    /**
     * The control. An absorbed closing prayer is the rule working as intended — the sermon's
     * conclusion, not a separate item — so absorbing one on its own raises nothing.
     */
    #[Test]
    public function it_does_not_absorb_an_unrelated_other_section(): void
    {
        $log = $this->runWithAbsorbedSection([]);

        $plan = $this->resolver->resolve($log);

        $this->assertCount(1, $plan['segments']);
        $this->assertSame(1157.0, $plan['segments'][0]['end_time']);

    }

    /**
     * Sermon 885: run 949's closing hymn sits inside sermon §723 itself. The flag holds the
     * sermon for review, so it must be registered as non-disqualifying or the held sermon drops
     * to the coarse baseline cut, which contains the hymn just the same and says nothing.
     */
    #[Test]
    public function a_sermon_holding_a_sung_span_keeps_its_identified_bounds(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'duration' => 5000.0,
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        $sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 2744.0,
            'end_time' => 4827.0,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => [ServiceStructureValidator::FLAG_SERMON_CONTAINS_SUNG_SPAN],
            ],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame($sermon->id, $plan['metadata']['sermon_section_id']);
        $this->assertSame(4827.0, $plan['segments'][0]['end_time']);

    }

    /**
     * A sermon followed by one trailing `other` section carrying the given review flags.
     *
     * Deliberately a single absorbed section with no order-of-service item behind it, so neither
     * `sermon_boundary_multiple_following_items` nor the independently-attested long tail fires
     * and the risk under test is the only one that can.
     *
     * @param  list<string>  $flags
     */
    private function runWithAbsorbedSection(array $flags): MediaProcessingLog
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 0.0,
            'end_time' => 1157.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Other->value,
            'title' => 'Lo He Comes With Clouds Descending',
            'start_time' => 1159.0,
            'end_time' => 1448.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'review_flags' => $flags],
        ]);

        return $log;
    }

    #[Test]
    public function it_prefers_high_confidence_sermon_section_when_bible_section_is_missing(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('single_span', $plan['mode']);
        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame(500.0, $plan['segments'][0]['start_time']);
        $this->assertSame(1200.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function it_accepts_a_sermon_section_whose_only_review_flag_is_a_cross_type_inversion(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // A cross-type OoS inversion questions item alignment, not the sermon's
        // boundaries — it must not push extraction onto the coarser baseline path.
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => ['structure_oos_cross_type_inversion'],
            ],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame(500.0, $plan['segments'][0]['start_time']);
        $this->assertSame(1200.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function it_accepts_a_sermon_section_flagged_only_for_a_missing_preached_reading(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // A missing preached reading questions what surrounds the sermon, not
        // its boundaries — extraction must not demote to the RMS baseline.
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => ['structure_missing_preached_reading'],
            ],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame(500.0, $plan['segments'][0]['start_time']);
        $this->assertSame(1200.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function it_parks_a_boundary_question_without_substituting_detector_bounds(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => ['structure_oos_cross_type_inversion', 'structure_low_confidence'],
            ],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('service_sections', $plan['source']);
        $this->assertTrue($plan['metadata']['requires_review']);
        $this->assertSame(500.0, $plan['segments'][0]['start_time']);

    }

    #[Test]
    public function it_parks_an_unexplained_review_hold_without_substituting_detector_bounds(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // Review was requested by something other than structure flags (e.g. an
        // operator) — stay conservative and fall back to the baseline.
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => true,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertTrue($plan['metadata']['requires_review']);

    }

    #[Test]
    public function it_keeps_adjacent_reading_and_sermon_as_exact_separate_spans(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'start_time' => 300.0,
            'end_time' => 600.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'reading_reference' => 'John 3:1-16'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 2,
            'start_time' => 630.0,
            'end_time' => 2100.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'sermon_reference' => 'John 3:1-16'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('concat_spans', $plan['mode']);
        $this->assertSame([['start_time' => 300.0, 'end_time' => 600.0], ['start_time' => 630.0, 'end_time' => 2100.0]], $plan['segments']);

    }

    /**
     * The forward mirror of the reading pairing: a closing prayer is the sermon's
     * conclusion, and sermon-to-prayer is the boundary the detector places least
     * reliably, so the published span runs through it rather than depending on it.
     */
    #[Test]
    public function it_adds_an_identified_closing_prayer_as_its_own_span(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60]);

        $log = $this->logWithSermon(630.0, 2100.0);
        $prayer = $this->section($log, ServiceSectionType::Prayer, 3, 2110.0, 2260.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame([['start_time' => 630.0, 'end_time' => 2100.0], ['start_time' => 2110.0, 'end_time' => 2260.0]], $plan['segments']);
        $this->assertSame([$prayer->id], $plan['metadata']['trailing_section_ids']);

    }

    #[Test]
    public function it_excludes_the_song_and_the_prayer_after_it(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60]);

        $log = $this->logWithSermon(630.0, 2100.0);
        $this->section($log, ServiceSectionType::Song, 3, 2110.0, 2300.0);
        $this->section($log, ServiceSectionType::Prayer, 4, 2310.0, 2400.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame(2100.0, $plan['segments'][0]['end_time']);
        $this->assertCount(1, $plan['segments']);

    }

    /**
     * The §4.1b section coverage census: a closing prayer the detector left
     * unsectioned was never published, because the span walked sections only.
     * Seven sermons (1027, 981, 1193, 1299, 1172, 986, 990) lost theirs.
     */
    #[Test]
    public function it_never_extends_into_uncovered_time_before_the_song(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60]);

        $log = $this->logWithSermon(630.0, 2100.0);
        $this->section($log, ServiceSectionType::Song, 3, 2250.0, 2450.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame(2100.0, $plan['segments'][0]['end_time']);
        $this->assertCount(1, $plan['segments']);

    }

    #[Test]
    public function an_identified_prayer_is_selected_without_a_proximity_rule(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60]);

        $log = $this->logWithSermon(630.0, 2100.0);
        $prayer = $this->section($log, ServiceSectionType::Prayer, 3, 2400.0, 2500.0);
        $this->section($log, ServiceSectionType::Song, 4, 2530.0, 2700.0);

        $plan = $this->resolver->resolve($log);

        $this->assertCount(2, $plan['segments']);
        $this->assertSame(['start_time' => 2400.0, 'end_time' => 2500.0], $plan['segments'][1]);

    }

    #[Test]
    public function it_refuses_an_unsectioned_extension_that_would_pass_the_sermon_ceiling(): void
    {
        config([
            'media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60,
            'media-processing.section_extraction.enhanced_sermon.max_sermon_duration_seconds' => 1500,
        ]);

        $log = $this->logWithSermon(630.0, 2100.0);
        $this->section($log, ServiceSectionType::Song, 3, 2200.0, 2400.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame(2100.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function it_excludes_an_unrelated_bridge_without_extending_to_the_song(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60]);

        $log = $this->logWithSermon(630.0, 2100.0);
        $bridge = $this->section($log, ServiceSectionType::Other, 3, 2110.0, 2150.0);
        $this->section($log, ServiceSectionType::Song, 4, 2160.0, 2300.0, title: 'Song introduction');

        $plan = $this->resolver->resolve($log);

        $this->assertSame(2100.0, $plan['segments'][0]['end_time']);
        $this->assertNotContains($bridge->id, $plan['metadata']['selected_section_ids']);

    }

    #[Test]
    public function it_selects_the_immediately_following_prayer_without_absorbing_other_items(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60]);

        $log = $this->logWithSermon(630.0, 2100.0);
        $this->section($log, ServiceSectionType::Prayer, 3, 2110.0, 2160.0);
        $this->section($log, ServiceSectionType::Other, 4, 2170.0, 2220.0);
        $this->section($log, ServiceSectionType::Song, 5, 2230.0, 2400.0);

        $plan = $this->resolver->resolve($log);

        $this->assertCount(2, $plan['segments']);
        $this->assertSame(2160.0, $plan['segments'][1]['end_time']);

    }

    /**
     * A section crossing the sermon end is recorded but raises no review hold.
     * The published span comes from the run-to-the-next-song rule and never
     * consults the overlap, so a reviewer would have no alternative span to
     * choose — and a needless hold pins the run's staged source.
     */
    #[Test]
    public function an_unselected_crossing_section_does_not_change_the_sermon_bounds(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60]);

        $log = $this->logWithSermon(630.0, 2100.0);
        // Starts 16.9 s before the sermon ends and runs well past it — the #971 shape.
        $crossing = $this->section($log, ServiceSectionType::Song, 3, 2083.1, 2400.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame(2100.0, $plan['segments'][0]['end_time']);
        $this->assertNotContains($crossing->id, $plan['metadata']['selected_section_ids']);

    }

    /**
     * Duration is never the sole authority for a review hold. A long tail that
     * only this recording attests reads as a long conclusion, so it stays in the
     * sermon automatically however long it runs.
     */
    #[Test]
    public function an_unidentified_tail_is_excluded_regardless_of_duration(): void
    {
        config([
            'media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60,
            'media-processing.section_extraction.enhanced_sermon.long_tail_review_seconds' => 120,
        ]);

        $log = $this->logWithSermon(630.0, 2100.0);
        $tail = $this->section($log, ServiceSectionType::Other, 3, 2110.0, 2350.0, title: 'Long conclusion');
        $tail->churchServiceItem->update(['source' => ChurchServiceItemSource::Livestream->value]);

        // A closing song after the tail must not be mistaken for corroboration:
        // nearly every service has one, so treating it as evidence would make
        // this a duration trigger wearing a corroboration label.
        $this->section($log, ServiceSectionType::Song, 4, 2400.0, 2560.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame(2100.0, $plan['segments'][0]['end_time']);
        $this->assertNotContains($tail->id, $plan['metadata']['selected_section_ids']);

    }

    /**
     * The same tail, once an order of service or service email attests it as an
     * item of its own, is the non-duration evidence that makes it reviewable.
     */
    #[Test]
    public function an_other_section_is_not_a_prayer_because_another_source_attests_it(): void
    {
        config([
            'media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60,
            'media-processing.section_extraction.enhanced_sermon.long_tail_review_seconds' => 120,
        ]);

        $log = $this->logWithSermon(630.0, 2100.0);
        $tail = $this->section($log, ServiceSectionType::Other, 3, 2110.0, 2350.0, title: 'Closing prayer');
        $tail->churchServiceItem->update(['source' => ChurchServiceItemSource::OpenLp->value]);

        $plan = $this->resolver->resolve($log);

        // Still cut inclusively -- the risk routes a reviewer to it, it does not
        // move the boundary.
        $this->assertSame(2100.0, $plan['segments'][0]['end_time']);
        $this->assertNotContains($tail->id, $plan['metadata']['selected_section_ids']);

    }

    #[Test]
    public function it_does_not_extend_across_a_gap_wider_than_the_adjacency_window(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60]);

        $log = $this->logWithSermon(630.0, 2100.0);
        $this->section($log, ServiceSectionType::Prayer, 3, 2400.0, 2500.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame(2100.0, $plan['segments'][0]['end_time']);
    }

    /**
     * The 2024-07-28 shape: the detector typed the sermon's conclusion `other`
     * after a mid-sermon reading, and the recording is a concatenation with its
     * songs excised, so there is no song to stop at.
     */
    #[Test]
    public function it_excludes_unrelated_readings_and_other_sections_after_the_sermon(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60]);

        $log = $this->logWithSermon(1896.0, 2690.0);
        $this->section($log, ServiceSectionType::BibleReading, 3, 2690.0, 2895.0);
        $this->section($log, ServiceSectionType::Other, 4, 2912.0, 3055.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame(2690.0, $plan['segments'][0]['end_time']);
        $this->assertCount(1, $plan['segments']);

    }

    #[Test]
    public function it_refuses_an_extension_that_would_pass_the_sermon_ceiling(): void
    {
        config([
            'media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60,
            'media-processing.section_extraction.enhanced_sermon.max_sermon_duration_seconds' => 1500,
        ]);

        $log = $this->logWithSermon(630.0, 2100.0);
        $this->section($log, ServiceSectionType::Prayer, 3, 2110.0, 2600.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame(2100.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function it_uses_concat_mode_for_non_adjacent_bible_and_sermon_when_enabled(): void
    {
        config([
            'media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60,
            'media-processing.section_extraction.enhanced_sermon.allow_non_adjacent_concat' => true,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'start_time' => 300.0,
            'end_time' => 600.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'reading_reference' => 'John 3:1-16'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 2,
            'start_time' => 1500.0,
            'end_time' => 2400.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'sermon_reference' => 'John 3:1-16'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('concat_spans', $plan['mode']);
        $this->assertCount(2, $plan['segments']);
        $this->assertSame(300.0, $plan['segments'][0]['start_time']);
        $this->assertSame(600.0, $plan['segments'][0]['end_time']);
        $this->assertSame(1500.0, $plan['segments'][1]['start_time']);
        $this->assertSame(2400.0, $plan['segments'][1]['end_time']);
    }

    #[Test]
    public function legacy_section_preference_cannot_enable_a_detector_fallback(): void
    {
        config(['media-processing.section_classification.prefer_high_confidence_sermon_section' => false]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 250.0,
            'sermon_end_time' => 1900.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame(500.0, $plan['segments'][0]['start_time']);

    }

    #[Test]
    public function a_reading_with_missing_references_raises_membership_review(): void
    {
        config([
            'media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60,
            'media-processing.section_extraction.enhanced_sermon.allow_non_adjacent_concat' => true,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 2,
            'start_time' => 1000.0,
            'end_time' => 2400.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertTrue($plan['metadata']['requires_review']);
        $this->assertCount(1, $plan['segments']);

    }

    #[Test]
    public function item_linkage_does_not_resolve_missing_reading_references(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // Closer but not marked as the day's scripture in the order of service.
        $this->reading($log, order: 1, start: 1800.0, end: 1900.0);

        $linkedReading = $this->reading($log, order: 2, start: 1500.0, end: 1650.0, itemId: $this->biblesOosItem()->id);

        $this->sermon($log, order: 3, start: 2000.0, end: 3500.0);

        $plan = $this->resolver->resolve($log);

        $this->assertTrue($plan['metadata']['requires_review']);
        $this->assertNull($plan['metadata']['bible_section_id']);

    }

    #[Test]
    public function proximity_does_not_resolve_missing_reading_references(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // Earliest in service order, but a long way before the sermon.
        $this->reading($log, order: 1, start: 1500.0, end: 1650.0);

        $closerReading = $this->reading($log, order: 2, start: 1750.0, end: 1900.0);

        $this->sermon($log, order: 3, start: 2000.0, end: 3500.0);

        $plan = $this->resolver->resolve($log);

        $this->assertTrue($plan['metadata']['requires_review']);
        $this->assertNull($plan['metadata']['bible_section_id']);

    }

    #[Test]
    public function duration_does_not_resolve_missing_reading_references(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.min_reading_duration_seconds' => 90]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        $substantiveReading = $this->reading($log, order: 1, start: 1400.0, end: 1700.0);

        // A 30-second "let us turn to..." preamble immediately before the sermon.
        $this->reading($log, order: 2, start: 1950.0, end: 1980.0);

        $this->sermon($log, order: 3, start: 2000.0, end: 3500.0);

        $plan = $this->resolver->resolve($log);

        $this->assertTrue($plan['metadata']['requires_review']);
        $this->assertNull($plan['metadata']['bible_section_id']);

    }

    #[Test]
    public function it_prefers_the_reading_matching_the_sermon_reference_over_a_longer_earlier_one(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.min_reading_duration_seconds' => 90]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // The 2023-05-07 corpus run: Psalm 72 (168 s) early in the service beat
        // the actual preached text — an adjacent 72-second Philippians reading
        // demoted by the substantive-duration tier — so the published audio
        // opened with the wrong passage.
        $this->reading($log, order: 1, start: 548.0, end: 716.0, reference: 'Psalm 72');

        $philippians = $this->reading($log, order: 2, start: 1106.0, end: 1178.0, reference: 'Philippians 2:5-11');

        $this->sermon($log, order: 3, start: 1372.0, end: 2914.0, reference: 'Philippians 2:5-11');

        $plan = $this->resolver->resolve($log);

        $this->assertSame($philippians->id, $plan['metadata']['bible_section_id']);
        $this->assertSame(1106.0, $plan['segments'][0]['start_time']);
    }

    /**
     * The §4.1b Scripture census: a same-type OoS inversion held the preached
     * reading, and the held-reading filter then dropped it from the sermon
     * media (1075, 1254, 1286, 1299). An ordering flag says nothing about the
     * reading's own boundaries.
     */
    #[Test]
    public function it_keeps_a_reading_held_only_for_an_ordering_flag_when_it_matches_the_sermon_reference(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        $this->reading($log, order: 1, start: 548.0, end: 716.0, reference: 'Psalm 72');

        $preachedText = $this->reading($log, order: 2, start: 1106.0, end: 1278.0, reference: 'Philippians 2:5-11');
        $preachedText->update([
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'reading_reference' => 'Philippians 2:5-11',
                'review_flags' => ['structure_oos_same_type_inversion'],
            ],
        ]);

        $this->sermon($log, order: 3, start: 1300.0, end: 2914.0, reference: 'Philippians 2:5-11');

        $plan = $this->resolver->resolve($log);

        $this->assertSame($preachedText->id, $plan['metadata']['bible_section_id']);
        $this->assertSame(1106.0, $plan['segments'][0]['start_time']);
    }

    #[Test]
    public function it_still_excludes_a_held_reading_that_does_not_match_the_sermon_reference(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        $heldReading = $this->reading($log, order: 1, start: 1106.0, end: 1278.0, reference: 'Psalm 72');
        $heldReading->update([
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'reading_reference' => 'Psalm 72',
                'review_flags' => ['structure_oos_same_type_inversion'],
            ],
        ]);

        $this->sermon($log, order: 2, start: 1300.0, end: 2914.0, reference: 'Philippians 2:5-11');

        $plan = $this->resolver->resolve($log);

        $this->assertNull($plan['metadata']['bible_section_id'] ?? null);
        $this->assertSame(1300.0, $plan['segments'][0]['start_time']);
    }

    #[Test]
    public function it_matches_a_sermon_reference_that_is_a_subrange_of_the_reading(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.min_reading_duration_seconds' => 90]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // A sermon usually expounds part of the passage read; overlap, not
        // equality, is the match criterion.
        $this->reading($log, order: 1, start: 1400.0, end: 1700.0, reference: 'Psalm 113');

        $preachedText = $this->reading($log, order: 2, start: 1900.0, end: 1970.0, reference: '1 Timothy 3:14-4:16');

        $this->sermon($log, order: 3, start: 2000.0, end: 3500.0, reference: '1 Timothy 4:7-10');

        $plan = $this->resolver->resolve($log);

        $this->assertSame($preachedText->id, $plan['metadata']['bible_section_id']);
    }

    #[Test]
    public function a_far_reading_without_references_requires_membership_review(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.max_pairing_gap_seconds' => 900]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // An opening-notices verse ~20 minutes before the sermon.
        $this->reading($log, order: 1, start: 500.0, end: 800.0);

        $this->sermon($log, order: 2, start: 2000.0, end: 3500.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('service_sections', $plan['source']);
        $this->assertTrue($plan['metadata']['requires_review']);

    }

    #[Test]
    public function an_accepted_long_sermon_has_no_cut_time_duration_ceiling(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.max_sermon_duration_seconds' => 2700]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 0.0,
            'sermon_end_time' => 3916.0,
        ]);

        // A 65-minute "sermon" section — RMS under-segmentation collapsing the whole service.
        $this->sermon($log, order: 1, start: 0.0, end: 3916.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('service_sections', $plan['source']);
        $this->assertCount(1, $plan['segments']);

    }

    private function reading(
        MediaProcessingLog $log,
        int $order,
        float $start,
        float $end,
        ?int $itemId = null,
        ?string $reference = null,
    ): ServiceSection {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => $itemId,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => $order,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'needs_manual_review' => false,
        ] + ($reference === null ? [] : [
            'metadata' => ['confidence_level' => 'high', 'reading_reference' => $reference],
        ]));
    }

    private function sermon(
        MediaProcessingLog $log,
        int $order,
        float $start,
        float $end,
        ?string $reference = null,
    ): ServiceSection {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => $order,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'needs_manual_review' => false,
        ] + ($reference === null ? [] : [
            'metadata' => ['confidence_level' => 'high', 'sermon_reference' => $reference],
        ]));
    }

    private function biblesOosItem(): ChurchServiceItem
    {
        $service = ChurchService::factory()->create();

        return ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'type' => 'bibles',
            'title' => 'Colossians 1:15-23',
        ]);
    }

    private function logWithSermon(float $start, float $end): MediaProcessingLog
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        $this->section($log, ServiceSectionType::Sermon, 2, $start, $end);

        return $log;
    }

    private function section(
        MediaProcessingLog $log,
        ServiceSectionType $type,
        int $order,
        float $start,
        float $end,
        ?string $title = null,
    ): ServiceSection {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => $type->value,
            'section_order' => $order,
            'title' => $title,
            'start_time' => $start,
            'end_time' => $end,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);
    }

    #[Test]
    public function it_spans_a_sermon_delivered_in_two_parts_around_a_hymn(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        $sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        // The hymn the preacher paused for. It must never reach the published span.
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 1200.0,
            'end_time' => 1450.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 3,
            'start_time' => 1450.0,
            'end_time' => 1900.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'sermon_continuation' => [
                    'of_section_id' => $sermon->id,
                    'evidence' => 'This is a continuation of the single sermon, separated by a congregational song.',
                    'source' => 'detector_notes',
                ],
            ],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('concat_spans', $plan['mode']);
        $this->assertSame('service_sections', $plan['source']);
        $this->assertCount(2, $plan['segments']);
        $this->assertSame(500.0, $plan['segments'][0]['start_time']);
        $this->assertSame(1200.0, $plan['segments'][0]['end_time']);
        $this->assertSame(1450.0, $plan['segments'][1]['start_time']);
        $this->assertSame(1900.0, $plan['segments'][1]['end_time']);
    }

    #[Test]
    public function it_orders_three_sermon_parts_and_keeps_every_intervening_song_out(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        $sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 500.0,
            'end_time' => 1000.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        foreach ([[1000.0, 1200.0, 2], [1700.0, 1900.0, 4]] as [$start, $end, $order]) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $log->id,
                'section_type' => ServiceSectionType::Song->value,
                'section_order' => $order,
                'start_time' => $start,
                'end_time' => $end,
                'needs_manual_review' => false,
                'metadata' => ['confidence_level' => 'high'],
            ]);
        }

        // Deliberately created out of order, so the plan cannot be right by insertion luck.
        foreach ([[1900.0, 2300.0, 5], [1200.0, 1700.0, 3]] as [$start, $end, $order]) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $log->id,
                'section_type' => ServiceSectionType::Other->value,
                'section_order' => $order,
                'start_time' => $start,
                'end_time' => $end,
                'needs_manual_review' => false,
                'metadata' => [
                    'confidence_level' => 'high',
                    'sermon_continuation' => [
                        'of_section_id' => $sermon->id,
                        'evidence' => 'This is the concluding continuation of the single sermon.',
                        'source' => 'detector_notes',
                    ],
                ],
            ]);
        }

        $plan = $this->resolver->resolve($log);

        $this->assertSame('concat_spans', $plan['mode']);
        $this->assertSame(
            [[500.0, 1000.0], [1200.0, 1700.0], [1900.0, 2300.0]],
            array_map(
                static fn (array $segment): array => [$segment['start_time'], $segment['end_time']],
                $plan['segments'],
            ),
        );
    }

    #[Test]
    public function it_ignores_an_other_section_that_is_not_marked_as_a_sermon_continuation(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 1200.0,
            'end_time' => 1450.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        // Run 1148's §2201 shape: a long `other` the detector explicitly declined to
        // call sermon. Length alone must never admit a section to the published span.
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 3,
            'start_time' => 1450.0,
            'end_time' => 2500.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('single_span', $plan['mode']);
        $this->assertCount(1, $plan['segments']);
        $this->assertSame(1200.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function it_records_which_sections_the_continuation_spans_came_from(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        $sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 1200.0,
            'end_time' => 1450.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $continuation = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 3,
            'start_time' => 1450.0,
            'end_time' => 1900.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'sermon_continuation' => [
                    'of_section_id' => $sermon->id,
                    'evidence' => 'This is the concluding continuation of the single sermon.',
                    'source' => 'detector_notes',
                ],
            ],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame([$continuation->id], $plan['metadata']['continuation_section_ids']);
        $this->assertSame('identified_sections', $plan['metadata']['strategy']);
    }

    #[Test]
    public function it_places_a_sermon_part_that_precedes_the_sermon_section_in_delivery_order(): void
    {
        // Run 1073's shape: §1711 is "the first part of the main sermon, which resumes
        // after the intervening hymn" and starts twenty minutes before the section the
        // detector typed `sermon`. Appending it would publish the sermon back to front.
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        $sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 3,
            'start_time' => 2894.0,
            'end_time' => 3593.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 1654.0,
            'end_time' => 2653.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'sermon_continuation' => [
                    'of_section_id' => $sermon->id,
                    'evidence' => 'This is the first part of the main sermon, which resumes after the intervening hymn.',
                    'source' => 'detector_notes',
                ],
            ],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 2653.0,
            'end_time' => 2894.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('concat_spans', $plan['mode']);
        $this->assertSame(
            [[1654.0, 2653.0], [2894.0, 3593.0]],
            array_map(
                static fn (array $segment): array => [$segment['start_time'], $segment['end_time']],
                $plan['segments'],
            ),
        );
    }

    #[Test]
    public function a_separate_marked_continuation_has_its_own_exact_span(): void
    {
        // `resolveSermonEnd()` runs the published span forward through trailing `other`
        // material, so a part directly after the sermon is inside the span already.
        // Adding it again would play the passage twice and slice its text twice.
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        $sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 1200.0,
            'end_time' => 1400.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'sermon_continuation' => [
                    'of_section_id' => $sermon->id,
                    'evidence' => 'This is the continuation and conclusion of the primary sermon.',
                    'source' => 'detector_notes',
                ],
            ],
        ]);

        $plan = $this->resolver->resolve($log);

        // Absorbed by the sermon-end rule, so one contiguous span, counted once.
        $this->assertSame('concat_spans', $plan['mode']);
        $this->assertCount(2, $plan['segments']);
        $this->assertSame(500.0, $plan['segments'][0]['start_time']);
        $this->assertSame(1400.0, $plan['segments'][1]['end_time']);

    }

    #[Test]
    public function accepted_continuations_have_no_cut_time_duration_ceiling(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        $sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 500.0,
            'end_time' => 2600.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 2600.0,
            'end_time' => 2900.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $rejected = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 3,
            'start_time' => 2900.0,
            'end_time' => 4000.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'sermon_continuation' => [
                    'of_section_id' => $sermon->id,
                    'evidence' => 'This is the concluding continuation of the single sermon.',
                    'source' => 'detector_notes',
                ],
            ],
        ]);

        $plan = $this->resolver->resolve($log);

        // Refused, but never silently: discarding what the detector named is the
        // defect this item exists to correct, so the refusal is on the record.
        $this->assertSame('concat_spans', $plan['mode']);
        $this->assertCount(2, $plan['segments']);
        $this->assertContains($rejected->id, $plan['metadata']['selected_section_ids']);

    }

    #[Test]
    public function it_ignores_a_continuation_marker_naming_a_different_sermon_section(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 1200.0,
            'end_time' => 1450.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        // A marker carried over from another run names a section this run does not
        // hold. Presence of the marker must not be enough to admit the span.
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 3,
            'start_time' => 1450.0,
            'end_time' => 1900.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'sermon_continuation' => [
                    'of_section_id' => 999999,
                    'evidence' => 'This is the concluding continuation of the single sermon.',
                    'source' => 'detector_notes',
                ],
            ],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('single_span', $plan['mode']);
        $this->assertCount(1, $plan['segments']);
    }

    /**
     * Run 1209 (§2572), 2026-09-17 canary. A content hold says the sermon cannot be
     * released as it stands. On its own it also has to keep automatic extraction off
     * the section: the same hold records disputed spans, and nothing in it says which
     * kind of defect it is.
     */
    #[Test]
    public function it_does_not_cut_a_content_held_sermon_without_authority(): void
    {
        [$log] = $this->runWithHeldSermon();

        $plan = $this->resolver->resolve($log);

        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame('sermon_section_content_held', $plan['metadata']['reason']);
        $this->assertSame([$log->serviceSections()->sole()->id], $plan['metadata']['held_sermon_section_ids']);
    }

    /**
     * The repair for 1209 is a re-cut of the span the hold does not dispute (its MP3
     * lost the closing words). An operator names the section; the plan then comes from
     * it, and says so.
     */
    #[Test]
    public function it_cuts_a_content_held_sermon_an_operator_authorised(): void
    {
        [$log, $sermon] = $this->runWithHeldSermon();
        $log->authoriseHeldSermonSpan($sermon);

        $plan = $this->resolver->resolve($log->fresh());

        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame([['start_time' => 2123.0, 'end_time' => 3740.0]], $plan['segments']);
        $this->assertSame($sermon->id, $plan['metadata']['sermon_section_id']);
        $this->assertSame($sermon->id, $plan['metadata']['held_span_authorised_section_id']);
    }

    /**
     * A dry run has to show the plan an execution would follow, before anything is
     * recorded on the run.
     */
    #[Test]
    public function it_resolves_an_authority_offered_without_recording_it(): void
    {
        [$log, $sermon] = $this->runWithHeldSermon();

        $plan = $this->resolver->resolve($log, MediaProcessingLog::heldSermonSpanAuthorityFor($sermon));

        $this->assertSame('service_sections', $plan['source']);
        $this->assertNull($log->fresh()->authorisedHeldSermonSpan());
    }

    /**
     * The authority vouches for one span. A re-detection that moves the section has
     * produced a span nobody looked at, so the authority no longer applies.
     */
    #[Test]
    public function it_ignores_an_authority_once_the_held_sermon_has_moved(): void
    {
        [$log, $sermon] = $this->runWithHeldSermon();
        $log->authoriseHeldSermonSpan($sermon);
        $sermon->update(['end_time' => 3700.0, 'duration' => 1577.0]);

        $plan = $this->resolver->resolve($log->fresh());

        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame('sermon_section_content_held', $plan['metadata']['reason']);
    }

    /**
     * Naming a section lifts the content hold only. A flag that says the span itself
     * is unsound still refuses it.
     */
    #[Test]
    public function it_still_refuses_an_authorised_sermon_whose_span_is_unsound(): void
    {
        [$log, $sermon] = $this->runWithHeldSermon([ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED]);
        $log->authoriseHeldSermonSpan($sermon);

        $plan = $this->resolver->resolve($log->fresh());

        $this->assertSame('service_sections', $plan['source']);
    }

    /**
     * @param  list<string>  $extraFlags
     * @return array{MediaProcessingLog, ServiceSection}
     */
    private function runWithHeldSermon(array $extraFlags = []): array
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'sermon_start_time' => 1758.0,
            'sermon_end_time' => 3740.0,
        ]);

        $sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 2123.0,
            'end_time' => 3740.0,
            'duration' => 1617.0,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => [HoldSectionForContentReview::FLAG, ...$extraFlags],
                HoldSectionForContentReview::METADATA_KEY => [[
                    'reason' => 'Sermon MP3 ends before its video and loses the closing words',
                    'evidence' => 'duration census',
                    'held_at' => '2026-09-13T20:23:31+00:00',
                ]],
            ],
        ]);

        return [$log, $sermon];
    }
}
