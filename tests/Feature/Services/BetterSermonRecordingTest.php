<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\ProcessingStatus;
use App\Enums\TalkType;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Sermon\BetterSermonRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BetterSermonRecordingTest extends TestCase
{
    use RefreshDatabase;

    private ChurchService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = ChurchService::factory()->create();
    }

    /** 894 and 1126: the 720p livestream adopts the 1080p capture's sermon. */
    #[Test]
    public function a_worse_recording_finds_the_sermon_a_better_one_holds(): void
    {
        $livestream = $this->recording(720, 490_906);
        $capture = $this->recording(1080, 2_663_497);
        $sermon = $this->sermonOf($capture, 2007.8);

        $found = app(BetterSermonRecording::class)->holderFor($livestream, 2050.0);

        $this->assertTrue($sermon->is($found['sermon'] ?? null));
        $this->assertTrue($capture->is($found['holder'] ?? null));
    }

    #[Test]
    public function the_same_resolution_is_decided_by_bitrate(): void
    {
        $livestream = $this->recording(1080, 1_000_000);
        $this->sermonOf($this->recording(1080, 2_000_000), 2000.0);

        $this->assertNotNull(app(BetterSermonRecording::class)->holderFor($livestream, 2000.0));
    }

    /** An equal or worse recording never takes the sermon away; the run cuts its own as before. */
    #[Test]
    public function an_equal_or_worse_recording_holds_nothing_for_this_run(): void
    {
        $run = $this->recording(1080, 2_000_000);
        $this->sermonOf($this->recording(1080, 2_000_000), 2000.0);
        $this->sermonOf($this->recording(720, 3_000_000), 2000.0);

        $this->assertNull(app(BetterSermonRecording::class)->holderFor($run, 2000.0));
    }

    /** A capture that started partway through the sermon does not win on picture alone. */
    #[Test]
    public function a_better_recording_missing_part_of_the_sermon_is_not_adopted(): void
    {
        $livestream = $this->recording(720, 490_906);
        $this->sermonOf($this->recording(1080, 2_663_497), 1800.0);

        $this->assertNull(app(BetterSermonRecording::class)->holderFor($livestream, 2000.0));
    }

    #[Test]
    public function an_excluded_recording_or_a_childrens_talk_is_never_the_holder(): void
    {
        $livestream = $this->recording(720, 490_906);
        $excluded = $this->recording(1080, 2_663_497);
        $excluded->putExclusion(MediaProcessingLog::EXCLUSION_REASON_DUPLICATE_RECORDING, ['note' => 'Set aside.']);
        $this->sermonOf($excluded, 2000.0);
        Sermon::factory()->create([
            'livestream_processing_id' => $this->recording(1080, 2_663_497)->processing_id,
            'content_type' => TalkType::ChildrensTalk,
            'duration' => 2000.0,
        ]);

        $this->assertNull(app(BetterSermonRecording::class)->holderFor($livestream, 2000.0));
    }

    #[Test]
    public function the_best_of_several_better_recordings_holds_the_sermon(): void
    {
        $livestream = $this->recording(480, 300_000);
        $this->sermonOf($this->recording(720, 900_000), 2000.0);
        $best = $this->sermonOf($this->recording(1080, 2_500_000), 2000.0);
        $this->sermonOf($this->recording(1080, 1_500_000), 2000.0);

        $this->assertTrue($best->is(app(BetterSermonRecording::class)->holderFor($livestream, 2000.0)['sermon'] ?? null));
    }

    #[Test]
    public function a_run_without_a_service_or_a_measured_source_compares_nothing(): void
    {
        $this->sermonOf($this->recording(1080, 2_663_497), 2000.0);
        $unmeasured = MediaProcessingLog::factory()->create(['church_service_id' => $this->service->id, 'processing_metadata' => []]);
        $unassigned = $this->recording(720, 490_906);
        $unassigned->forceFill(['church_service_id' => null])->save();

        $this->assertNull(app(BetterSermonRecording::class)->holderFor($unmeasured, 2000.0));
        $this->assertNull(app(BetterSermonRecording::class)->holderFor($unassigned, 2000.0));
    }

    /** Replacing a sermon the run already made is a re-cut's decision, never adoption's. */
    #[Test]
    public function a_run_that_made_its_own_sermon_never_adopts_another(): void
    {
        $livestream = $this->recording(720, 490_906);
        $this->sermonOf($livestream, 2000.0);
        $this->sermonOf($this->recording(1080, 2_663_497), 2000.0);

        $this->assertNull(app(BetterSermonRecording::class)->holderFor($livestream, 2000.0));
    }

    /** The holder stays releasable and re-cuttable only while a standing run adopted its sermon. */
    #[Test]
    public function a_run_holds_an_adopted_sermon_only_while_a_standing_run_adopted_it(): void
    {
        $capture = $this->recording(1080, 2_663_497);
        $sermon = $this->sermonOf($capture, 2007.8);
        $unrelated = $this->recording(1080, 2_663_497);
        $livestream = $this->recording(720, 490_906);

        $this->assertFalse($capture->holdsAdoptedSermon());

        $livestream->forceFill(['processing_metadata' => [
            ...$livestream->processing_metadata?->toArray() ?? [],
            'sermon_adopted' => ['sermon_id' => $sermon->id, 'holder_processing_id' => $capture->processing_id],
        ]])->save();

        $this->assertTrue($capture->fresh()?->holdsAdoptedSermon());
        $this->assertFalse($unrelated->holdsAdoptedSermon());
        $this->assertTrue($sermon->is($livestream->fresh()?->adoptedSermon()));

        $livestream->fresh()?->putExclusion(MediaProcessingLog::EXCLUSION_REASON_DUPLICATE_RECORDING, ['note' => 'Set aside.']);

        $this->assertFalse($capture->fresh()?->holdsAdoptedSermon());
    }

    #[Test]
    public function a_run_retired_through_withdrawal_holds_nothing(): void
    {
        $capture = $this->recording(1080, 2_663_497);
        $sermon = $this->sermonOf($capture, 2007.8);
        $livestream = $this->recording(720, 490_906);
        $livestream->forceFill(['processing_metadata' => ['sermon_adopted' => ['sermon_id' => $sermon->id, 'holder_processing_id' => $capture->processing_id]]])->save();
        $capture->putRetirement(['sermon_id' => $sermon->id]);

        $this->assertFalse($capture->fresh()?->holdsAdoptedSermon());
    }

    private function recording(int $height, int $bitRate): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->create([
            'church_service_id' => $this->service->id,
            'status' => ProcessingStatus::Completed,
            'processing_metadata' => ['format_details' => ['height' => $height, 'bit_rate' => $bitRate]],
        ]);
    }

    private function sermonOf(MediaProcessingLog $recording, float $duration): Sermon
    {
        return Sermon::factory()->create([
            'livestream_processing_id' => $recording->processing_id,
            'content_type' => TalkType::Sermon,
            'duration' => $duration,
        ]);
    }
}
