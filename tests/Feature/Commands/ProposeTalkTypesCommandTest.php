<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Contracts\TalkTypeClassifier;
use App\Data\TalkTypeInput;
use App\Data\TalkTypeProposal;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
use App\Enums\TalkType;
use App\Jobs\ProposeSectionTalkTypes;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\TalkTypeProposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProposeTalkTypesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['filesystems.disks.b4' => ['driver' => 'local', 'root' => storage_path('scratch/talk-type-build-20261115-b4/test-artifacts')], 'media-processing.storage.service_artifact_disk' => 'b4']);
    }

    #[Test]
    public function preview_writes_nothing(): void
    {
        $section = $this->section();
        $before = $section->fresh()->getRawOriginal();
        $runBefore = $section->processingLog->fresh()->getRawOriginal();
        $this->mock(TalkTypeClassifier::class)->shouldNotReceive('classify');
        Bus::fake();
        Queue::fake();
        $this->artisan('service:propose-talk-types', ['--section' => [$section->id]])
            ->expectsOutputToContain('eligible new')->assertSuccessful();
        $this->assertSame($before, $section->fresh()->getRawOriginal());
        $this->assertSame($runBefore, $section->processingLog->fresh()->getRawOriginal());
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function apply_calls_only_the_proposer(): void
    {
        $section = $this->section();
        $before = $section->fresh()->getRawOriginal();
        $runBefore = $section->processingLog->fresh()->getRawOriginal();
        $this->mock(TalkTypeClassifier::class)->shouldReceive('classify')->once()
            ->withArgs(fn (TalkTypeInput $input, TalkTypeProposal $rules): bool => $input->sectionId === $section->id && $rules->signals['needs_model'])
            ->andReturn($this->answer());
        Bus::fake();
        Queue::fake();
        $this->artisan('service:propose-talk-types', ['--run' => $section->media_processing_log_id, '--apply' => true])->assertSuccessful();
        $after = $section->fresh()->getRawOriginal();
        unset($before['metadata'], $before['updated_at'], $after['metadata'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame($runBefore, $section->processingLog->fresh()->getRawOriginal());
        $talk = $section->fresh()->metadata->talkType->raw;
        $this->assertSame('church_update', $talk['proposed']);
        $this->assertSame('talk-type-prompt-1', $talk['versions']['prompt']);
        $this->assertSame('talk-type-rules-1', $talk['versions']['rules']);
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        $before = $section->fresh()->getRawOriginal();
        $this->artisan('service:propose-talk-types', ['--section' => [$section->id], '--apply' => true])->expectsOutputToContain('unchanged')->assertSuccessful();
        $this->assertSame($before, $section->fresh()->getRawOriginal());
    }

    #[Test]
    public function excluded_superseded_and_unstable_sections_are_not_classified(): void
    {
        $sections = [];
        foreach ([ProcessingStatus::Pending, ProcessingStatus::Processing, ProcessingStatus::Cancelled] as $status) {
            $s = $this->section();
            $s->processingLog->update(['status' => $status]);
            $sections[] = $s;
        }
        $excluded = $this->section();
        $excluded->processingLog->update(['processing_metadata' => ['exclusion' => ['reason' => MediaProcessingLog::EXCLUSION_REASON_DUPLICATE_RECORDING]]]);
        $sections[] = $excluded;
        $superseded = $this->section();
        $superseded->processingLog->update(['superseded_at' => now()]);
        $sections[] = $superseded;
        $unstable = $this->section();
        ServiceSection::factory()->create(['media_processing_log_id' => $unstable->media_processing_log_id, 'church_service_item_id' => null, 'section_type' => ServiceSectionType::BibleReading, 'start_time' => 140, 'end_time' => 180]);
        $sections[] = $unstable;
        $upstream = $this->section();
        $upstream->processingLog->update(['status' => ProcessingStatus::Failed, 'current_step' => 'detect_service_structure']);
        $sections[] = $upstream;
        $rejected = $this->section();
        $rejected->processingLog->update(['status' => ProcessingStatus::Failed, 'current_step' => 'manual_review_required', 'processing_metadata' => ['manual_review' => ['reason_code' => 'llm_structure_validation_failed']]]);
        $sections[] = $rejected;
        $overlap = $this->section();
        ServiceSection::factory()->create(['media_processing_log_id' => $overlap->media_processing_log_id, 'church_service_item_id' => null, 'section_type' => ServiceSectionType::Sermon, 'start_time' => 120, 'end_time' => 900]);
        $sections[] = $overlap;
        $before = array_map(fn (ServiceSection $s): array => $s->fresh()->getRawOriginal(), $sections);
        $this->mock(TalkTypeClassifier::class)->shouldNotReceive('classify');
        $this->artisan('service:propose-talk-types', ['--section' => array_map(fn (ServiceSection $s): int => $s->id, $sections), '--apply' => true])->expectsOutputToContain('skipped')->assertSuccessful();
        $this->assertSame($before, array_map(fn (ServiceSection $s): array => $s->fresh()->getRawOriginal(), $sections));
    }

    #[Test]
    public function per_section_failure_is_bounded_and_does_not_stop_other_sections(): void
    {
        $first = $this->section();
        $second = $this->section();
        $this->mock(TalkTypeClassifier::class)->shouldReceive('classify')->times(4)
            ->andReturnUsing(function (TalkTypeInput $input) use ($first): TalkTypeProposal {
                if ($input->sectionId === $first->id) {
                    throw new \RuntimeException('Temporary provider failure');
                }

                return $this->answer();
            });
        $this->artisan('service:propose-talk-types', ['--section' => [$first->id, $second->id], '--apply' => true])->assertFailed();
        $failed = $first->fresh()->metadata->talkType->proposal();
        $this->assertNull($failed->proposed);
        $this->assertSame('unresolved', $failed->status);
        $this->assertSame(3, $failed->provenance['attempts']);
        $this->assertTrue($failed->signals['retry_exhausted']);
        $this->assertSame(TalkType::ChurchUpdate, $second->fresh()->metadata->talkType->proposed);
    }

    #[Test]
    public function rules_decide_before_the_model_and_unknown_service_family_answers_stay_unresolved(): void
    {
        $rule = $this->section();
        Storage::disk('b4')->put('service-transcripts/b4-'.$rule->media_processing_log_id.'.json', json_encode(['cues' => [['start' => 100, 'end' => 160, 'text' => 'Today we continue our Catechism series.']], 'duration' => 300, 'source' => 'sidecar'], JSON_THROW_ON_ERROR));
        $unknown = $this->section();
        $unknown->processingLog->update(['extracted_service' => null]);
        $this->mock(TalkTypeClassifier::class)->shouldReceive('classify')->once()
            ->withArgs(fn (TalkTypeInput $input): bool => $input->sectionId === $unknown->id)
            ->andReturn(new TalkTypeProposal(proposed: TalkType::ChildrensTalk, disposition: 'proposed', confidence: 0.9, decidedBy: 'model', reasons: ['family cues']));
        app(TalkTypeProposer::class)->propose($rule);
        app(TalkTypeProposer::class)->propose($unknown);
        $this->assertSame('rule', $rule->fresh()->metadata->talkType->proposal()->decidedBy);
        $this->assertSame('unresolved', $unknown->fresh()->metadata->talkType->proposal()->status);
        $this->assertNull($unknown->fresh()->metadata->talkType->proposed);
    }

    #[Test]
    public function input_versions_and_explicit_refresh_are_identity_bound(): void
    {
        $section = $this->section();
        $proposer = app(TalkTypeProposer::class);
        $a = $proposer->input($section);
        config(['media-processing.talk_type.reasoning_effort' => 'high']);
        $b = $proposer->input($section);
        $this->assertNotSame($a->inputHash(), $b->inputHash());
        $this->assertNotSame($b->inputHash(), $proposer->input($section, 'evaluation sample B4')->inputHash());
        $versions = $b->versions;
        $versions['rules'] .= '-changed';
        $this->assertNotSame($b->inputHash(), (new TalkTypeInput($b->sectionId, $b->evidence, $b->provenance, $versions))->inputHash());
        $versions = $b->versions;
        $versions['prompt'] .= '-changed';
        $this->assertNotSame($b->inputHash(), (new TalkTypeInput($b->sectionId, $b->evidence, $b->provenance, $versions))->inputHash());
    }

    #[Test]
    public function changed_inputs_or_run_state_in_flight_are_refused(): void
    {
        $section = $this->section();
        $this->mock(TalkTypeClassifier::class)->shouldReceive('classify')->once()->andReturnUsing(function () use ($section): TalkTypeProposal {
            $section->processingLog->update(['status' => ProcessingStatus::Processing]);

            return $this->answer();
        });
        $this->assertSame('ineligible', app(TalkTypeProposer::class)->propose($section));
        $this->assertNull($section->fresh()->metadata->talkType);
    }

    #[Test]
    public function settled_detection_only_and_post_review_sections_need_no_media(): void
    {
        $section = $this->section();
        $section->processingLog->update(['status' => ProcessingStatus::Failed, 'current_step' => 'manual_review_required']);
        $this->mock(TalkTypeClassifier::class)->shouldReceive('classify')->once()->andReturn($this->answer());
        $this->artisan('service:propose-talk-types', ['--run' => $section->media_processing_log_id, '--apply' => true])->assertSuccessful();
        $this->assertNull($section->fresh()->extracted_audio_path);
    }

    #[Test]
    public function chain_job_uses_the_same_proposer_and_continues_after_terminal_model_failure(): void
    {
        $first = $this->section();
        $second = ServiceSection::factory()->create(['media_processing_log_id' => $first->media_processing_log_id, 'church_service_item_id' => null, 'section_order' => 2, 'section_type' => ServiceSectionType::ShortTalk, 'title' => 'Update', 'start_time' => 200, 'end_time' => 260, 'metadata' => ['transcript' => 'An update.']]);
        $first->processingLog->update(['status' => ProcessingStatus::Processing]);
        $this->mock(TalkTypeClassifier::class)->shouldReceive('classify')->times(4)->andReturnUsing(fn (TalkTypeInput $input): TalkTypeProposal => $input->sectionId === $first->id ? new TalkTypeProposal(status: 'failed', reasons: ['Invalid response']) : $this->answer());
        Bus::fake();
        (new ProposeSectionTalkTypes($first->processingLog->fresh()))->handle(app(TalkTypeProposer::class));
        $this->assertSame('unresolved', $first->fresh()->metadata->talkType->proposal()->status);
        $this->assertSame(TalkType::ChurchUpdate, $second->fresh()->metadata->talkType->proposed);
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function explicit_selection_is_required(): void
    {
        $this->artisan('service:propose-talk-types')->assertFailed();
        $this->artisan('service:propose-talk-types', ['--section' => ['bad']])->assertFailed();
        $this->artisan('service:propose-talk-types', ['--section' => [1], '--run' => 1])->assertFailed();
    }

    #[Test]
    public function testimony_conflicts_report_reviewed_type_and_publication_state_without_clearing_them(): void
    {
        $section = $this->section();
        $section->update(['metadata' => ['talk_type' => ['reviewed' => ['value' => 'church_update']]]]);
        $before = $section->fresh()->publicationTalkType();
        $this->mock(TalkTypeClassifier::class)->shouldReceive('classify')->once()->andReturn(new TalkTypeProposal(
            proposed: TalkType::Testimony, disposition: 'proposed', confidence: 0.9, decidedBy: 'model',
            reasons: ['A personal account.'], signals: ['testimony_risk' => true, 'g10_withheld_by_default' => true],
        ));
        $this->artisan('service:propose-talk-types', ['--section' => [$section->id], '--apply' => true])
            ->expectsOutputToContain('testimony_risk=yes reviewed_type=church_update publication_status=not_applicable')->assertSuccessful();
        $this->assertSame($before, $section->fresh()->publicationTalkType());
    }

    #[Test]
    public function mixed_explicit_selection_reports_each_superseded_id(): void
    {
        $kept = $this->section();
        $old = $this->section();
        $old->processingLog->update(['superseded_at' => now()]);
        $this->mock(TalkTypeClassifier::class)->shouldNotReceive('classify');
        $this->artisan('service:propose-talk-types', ['--section' => [$kept->id, $old->id]])
            ->expectsOutputToContain($old->id.' skipped: missing_or_superseded')->assertSuccessful();
    }

    private function section(): ServiceSection
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create(['extracted_service' => 'morning', 'current_step' => 'cleanup']);
        Storage::disk('b4')->put('service-transcripts/b4-'.$run->id.'.json', json_encode(['cues' => [['start' => 100, 'end' => 160, 'text' => 'An update about the event.'], ['start' => 200, 'end' => 260, 'text' => 'Another event report.']], 'duration' => 300, 'source' => 'sidecar'], JSON_THROW_ON_ERROR));
        $run->putServiceTranscriptPath('service-transcripts/b4-'.$run->id.'.json');

        return ServiceSection::factory()->create(['media_processing_log_id' => $run->id, 'church_service_item_id' => null, 'section_order' => 1, 'section_type' => ServiceSectionType::ShortTalk, 'title' => 'Update', 'start_time' => 100, 'end_time' => 160, 'metadata' => ['transcript' => 'An update about the event.']]);
    }

    private function answer(): TalkTypeProposal
    {
        return new TalkTypeProposal(proposed: TalkType::ChurchUpdate, disposition: 'proposed', confidence: 0.8, reasons: ['A report about the event.'], decidedBy: 'model');
    }
}
