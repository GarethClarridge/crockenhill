<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Actions\HoldSectionForContentReview;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\PublicationPlanValidator;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\UntranscribedSpeechBeforeSection;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\UntranscribedSpeechRecovery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Operator, 2026-10-06: "Re-decode the F11 stretches; where they still have no words, include
 * them in both neighbours." Speech recovery could not decode is in neither section's words, so
 * no edge rule can place it; missing content is the defect and overlap between clips is not
 * (canary 10 ruling). The interval goes into both outputs whose sections border it, once.
 */
class UnresolvedIntervalInBothOutputsTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function an_interval_recovery_left_without_words_is_in_both_neighbouring_outputs(): void
    {
        [$log, $song, $prayer] = $this->service();
        $plans = app(CueSafeExtractionPlan::class);

        $songPlan = $plans->forSection($song);
        $prayerPlan = $plans->forSection($prayer);

        $this->assertGreaterThanOrEqual(630.8, $songPlan['segments'][0]['end_time']);
        $this->assertLessThanOrEqual(615.0, $prayerPlan['segments'][0]['start_time']);
        $audit = collect($prayerPlan['cue_edge_widening'])->firstWhere('reason', CueSafeExtractionPlan::UNRESOLVED_INTERVAL);
        $this->assertSame([615.0, 630.8], $audit['interval']);
        $this->assertSame($prayer->id, $audit['section_id']);
        $this->assertSame([['start' => 615.0, 'end' => 630.8, 'words' => 0]], $audit['recovery']);
        $this->assertSame([], app(PublicationPlanValidator::class)->validate($log, [$prayer], $prayerPlan['segments'], $prayerPlan['cue_edge_widening']));
    }

    /** Inside one composed output the interval is cut once: the spans either side of it merge. */
    #[Test]
    public function the_interval_occurs_once_in_a_composed_output(): void
    {
        [$log] = $this->service();

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log, [['start_time' => 400.0, 'end_time' => 610.0], ['start_time' => 630.8, 'end_time' => 700.0]]);

        $this->assertCount(1, $plan['segments']);
        $this->assertSame(400.0, $plan['segments'][0]['start_time']);
        $this->assertSame(700.0, $plan['segments'][0]['end_time']);
    }

    /** An output not bordering the interval is unchanged. */
    #[Test]
    public function an_output_away_from_the_interval_is_not_widened(): void
    {
        [$log] = $this->service();

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log, [['start_time' => 700.0, 'end_time' => 800.0]]);

        $this->assertSame([['start_time' => 700.0, 'end_time' => 800.0]], $plan['segments']);
    }

    /** The widened cut is validated after it widens: content held elsewhere still blocks it. */
    #[Test]
    public function the_widened_cut_does_not_bypass_an_unrelated_hold(): void
    {
        [$log, , $prayer] = $this->service();
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Other,
            'start_time' => 612.0, 'end_time' => 618.0,
            'metadata' => ServiceSectionMetadata::fromArray(['review_flags' => [HoldSectionForContentReview::FLAG]]),
        ]);
        $plan = app(CueSafeExtractionPlan::class)->forSection($prayer->fresh());

        $violations = app(PublicationPlanValidator::class)->validate($log->fresh(), [$prayer], $plan['segments'], $plan['cue_edge_widening']);

        $this->assertSame('crosses_held_section', $violations[0]['kind'] ?? null);
    }

    /** Once recovery decodes the stretch, the marker goes and nothing is widened. */
    #[Test]
    public function an_interval_without_the_marker_is_not_included(): void
    {
        [$log, , $prayer] = $this->service(flagged: false);

        $plan = app(CueSafeExtractionPlan::class)->forSection($prayer);

        $this->assertSame(630.8, $plan['segments'][0]['start_time']);
    }

    /**
     * Codex review: the fallback is for speech recovery could not decode. A marker with no failed
     * attempt behind it (never tried, or decoded since) is stale, and must not override a repair
     * (1025 §1373 had widened back over its corrected end).
     */
    #[Test]
    public function an_interval_recovery_did_not_fail_on_is_not_included(): void
    {
        foreach ([[], [['start' => 615.0, 'end' => 630.8, 'words' => 12, 'cues' => 2]], [['start' => 100.0, 'end' => 120.0, 'words' => 0, 'cues' => 0]]] as $attempts) {
            [, , $prayer] = $this->service(attempts: $attempts);

            $plan = app(CueSafeExtractionPlan::class)->forSection($prayer);

            $this->assertSame(630.8, $plan['segments'][0]['start_time'], json_encode($attempts, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     * @return array{0: MediaProcessingLog, 1: ServiceSection, 2: ServiceSection}
     */
    private function service(bool $flagged = true, array $attempts = [['start' => 615.0, 'end' => 630.8, 'words' => 0, 'cues' => 0]]): array
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $cues = [
            ['start' => 580.0, 'end' => 590.0, 'text' => 'Praise him, praise him, praise the everlasting King.'],
            ['start' => 640.0, 'end' => 645.0, 'text' => 'the Lord be with you all.'],
        ];
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000]);
        $log->putServiceTranscriptPath('temp/both.json');
        Storage::disk('local')->put('temp/both.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $log->writeProcessingMetadata(static function (array $metadata) use ($attempts): array {
            $metadata[UntranscribedSpeechRecovery::METADATA_KEY] = $attempts;

            return $metadata;
        });
        $song = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Song, 'start_time' => 400.0, 'end_time' => 610.0,
        ]);
        $prayer = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Prayer, 'start_time' => 630.8, 'end_time' => 700.0,
            'metadata' => ServiceSectionMetadata::fromArray($flagged ? [
                'review_flags' => [ServiceStructureValidator::FLAG_UNTRANSCRIBED_SPEECH_BEFORE_SECTION],
                'ai_notes' => ['Opening prayer.', UntranscribedSpeechBeforeSection::note(615.0, 630.8)],
            ] : []),
        ]);
        $evidence = app(OutputEdgeWordTimings::class);
        $window = $evidence->window($evidence->cues($log->fresh()), 640.0, 5000.0);
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($evidence->identity($log, $window)), [
            'identity' => $evidence->identity($log, $window), 'words' => [], 'compute_seconds' => 1.0,
        ]);

        return [$log->fresh(), $song->fresh(), $prayer->fresh()];
    }
}
