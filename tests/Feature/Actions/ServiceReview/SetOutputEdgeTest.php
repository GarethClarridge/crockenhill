<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\ServiceReview;

use App\Actions\HoldSectionForContentReview;
use App\Actions\ServiceReview\SetOutputEdge;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use App\Services\ChurchService\OutputEdgeAnswers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SetOutputEdgeTest extends TestCase
{
    use RefreshDatabase;

    private SetOutputEdge $action;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(SetOutputEdge::class);
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin);
    }

    /** 1224: the sermon's end, set by ear on a held sermon. Who, when, from what and the span are kept. */
    #[Test]
    public function it_records_the_end_on_the_sermon_without_releasing_its_hold_or_cutting(): void
    {
        Bus::fake();
        [$log, $sermon] = $this->heldSermonAfterItsReading();

        $this->action->execute($sermon, 'end', '1:06:47.6', $this->admin->id, 'ends before the hymn is read');

        $sermon->refresh();
        $record = OutputEdgeAnswers::recordsOf($sermon)['end'];
        $this->assertSame(4007.6, $record['time']);
        $this->assertSame($this->admin->id, $record['set_by_user_id']);
        $this->assertSame(4007.22, $record['from_time']);
        $this->assertEquals(['start_time' => 2545.22, 'end_time' => 4007.22], $record['section_span']);
        $this->assertSame($log->serviceSections()->orderBy('start_time')->where('section_type', '!=', ServiceSectionType::Song->value)->pluck('id')->all(), $record['output_section_ids']);
        $this->assertSame('ends before the hymn is read', $record['note']);
        $this->assertTrue(HoldSectionForContentReview::isHeld($sermon->metadata->reviewFlags));
        $this->assertTrue($sermon->needs_manual_review);
        $this->assertSame(4007.22, (float) $sermon->end_time, 'the section is unchanged: the answer is the output\'s');
        Bus::assertNotDispatched(PrepareSectionPublicationCandidates::class);
    }

    /** The output starts with its reading: "start here" is checked against the reading. */
    #[Test]
    public function the_start_is_the_outputs_first_part(): void
    {
        [, $sermon] = $this->heldSermonAfterItsReading();

        $this->action->execute($sermon, 'start', '2094.5', $this->admin->id);

        $this->assertSame(2093.92, OutputEdgeAnswers::recordsOf($sermon->fresh())['start']['from_time']);
    }

    #[Test]
    public function changing_and_clearing_an_answer_keeps_its_history(): void
    {
        [, $sermon] = $this->heldSermonAfterItsReading();

        $this->action->execute($sermon, 'end', '4007.6', $this->admin->id);
        $this->action->execute($sermon->fresh(), 'end', '4008.0', $this->admin->id);
        $this->action->execute($sermon->fresh(), 'end', null, $this->admin->id);

        $stored = $sermon->fresh()->metadata->raw[OutputEdgeAnswers::METADATA_KEY];
        $this->assertArrayNotHasKey('end', $stored);
        $this->assertEquals([4007.6, 4008.0], array_column($stored[OutputEdgeAnswers::HISTORY_KEY], 'time'));
        $this->assertEquals([4008.0, null], array_column($stored[OutputEdgeAnswers::HISTORY_KEY], 'replaced_with'));
        $this->assertSame([], OutputEdgeAnswers::recordsOf($sermon->fresh()));
    }

    /**
     * @param  'start'|'end'  $edge
     */
    #[Test]
    #[TestWith(['end', '5100', 'outside the recording'])]
    #[TestWith(['end', '2500', 'would leave out the whole last part'])]
    #[TestWith(['start', '2400', 'would leave out the whole first part'])]
    #[TestWith(['end', 'soon', 'Enter the time'])]
    public function it_refuses_an_edge_section_bounds_rule_out(string $edge, string $at, string $message): void
    {
        [, $sermon] = $this->heldSermonAfterItsReading();

        try {
            $this->action->execute($sermon, $edge, $at, $this->admin->id);
            $this->fail('The edge was recorded.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, $exception->errors()["output_edge.{$edge}"][0]);
        }

        $this->assertSame([], OutputEdgeAnswers::recordsOf($sermon->fresh()));
    }

    #[Test]
    public function an_end_must_come_after_the_start_already_set(): void
    {
        Bus::fake();
        $talk = ServiceSection::factory()->create(['media_processing_log_id' => MediaProcessingLog::factory()->livestream()->create(['duration' => 5000])->id,
            'section_type' => ServiceSectionType::ShortTalk->value, 'start_time' => 600.0, 'end_time' => 900.0]);
        $this->action->execute($talk, 'start', '800', $this->admin->id);

        $this->expectExceptionMessage('must come after the start already set at 13:20');

        $this->action->execute($talk->fresh(), 'end', '750', $this->admin->id);
    }

    #[Test]
    public function an_edge_reaching_into_a_held_section_the_output_does_not_cut_is_refused(): void
    {
        [$log, $sermon] = $this->heldSermonAfterItsReading();
        $song = $log->serviceSections()->where('start_time', 4008.21)->sole();
        $song->update(['metadata' => ['review_flags' => [HoldSectionForContentReview::FLAG]]]);

        $this->expectExceptionMessage('which is held for content review');

        $this->action->execute($sermon, 'end', '4012', $this->admin->id);
    }

    #[Test]
    public function only_a_sermon_or_a_talk_owns_an_output_edge(): void
    {
        [$log] = $this->heldSermonAfterItsReading();
        $song = $log->serviceSections()->where('section_type', ServiceSectionType::Song->value)->firstOrFail();

        $this->expectExceptionMessage('Only a sermon\'s or a talk\'s own start and end');

        $this->action->execute($song, 'end', '2540', $this->admin->id);
    }

    #[Test]
    public function a_published_section_is_not_recut_here(): void
    {
        [, $sermon] = $this->heldSermonAfterItsReading();
        $sermon->publication_status = ServiceSectionPublicationStatus::Published;

        $this->expectExceptionMessage('Published sections cannot be recut here.');

        $this->action->execute($sermon, 'end', '4007.6', $this->admin->id);
    }

    /** As the screen's talk recut does: the stale clip goes, and candidate preparation is queued. */
    #[Test]
    public function a_talks_edge_returns_its_clip_to_candidate_preparation(): void
    {
        Bus::fake();
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000]);
        $talk = ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::ShortTalk->value,
            'start_time' => 600.0, 'end_time' => 900.0, 'publication_status' => ServiceSectionPublicationStatus::Approved,
            'extracted_video_path' => 'sermons/talks/1.mp4', 'metadata' => ['publication' => ['approved_signature' => 'abc', 'approved_at' => '2026-10-16']]]);

        $this->action->execute($talk, 'end', '880.5', $this->admin->id);

        $talk->refresh();
        $this->assertSame(880.5, OutputEdgeAnswers::recordsOf($talk)['end']['time']);
        $this->assertNull($talk->extracted_video_path);
        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $talk->publication_status);
        $this->assertArrayNotHasKey('publication', $talk->metadata->raw);
        Bus::assertDispatched(PrepareSectionPublicationCandidates::class);
    }

    /** @return array{MediaProcessingLog, ServiceSection} */
    private function heldSermonAfterItsReading(): array
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000]);
        $reading = ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1, 'start_time' => 2093.92, 'end_time' => 2372.83]);
        ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2, 'start_time' => 2372.88, 'end_time' => 2543.31]);
        $sermon = ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 3, 'start_time' => 2545.22, 'end_time' => 4007.22, 'needs_manual_review' => true,
            'metadata' => ['review_flags' => [HoldSectionForContentReview::FLAG]]]);
        ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Song->value,
            'section_order' => 4, 'start_time' => 4008.21, 'end_time' => 4175.06]);
        $log->writeProcessingMetadata(static fn (array $metadata): array => [...$metadata,
            'sermon_composition' => ['sermon_section_id' => $sermon->id, 'selected_section_ids' => [$reading->id, $sermon->id]]]);

        return [$log->fresh(), $sermon->fresh()];
    }
}
