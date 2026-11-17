<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\ProposeTalkType;
use App\Data\TalkTypeProposal;
use App\Enums\ServiceSectionType;
use App\Enums\TalkType;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\ChurchService\TalkTypeInputBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TalkTypeProposalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function proposal_preserves_geometry_media_approval_holds_and_reviewed_facts(): void
    {
        $section = $this->section();
        $input = app(TalkTypeInputBuilder::class)->build($section, ['classifier' => 'b1']);
        $metadata = $section->metadata->toArray();
        $metadata['talk_type']['reviewed'] = ['value' => 'testimony', 'series' => 'Accepted series', 'by' => 1];
        $metadata['concurrent_note'] = 'Keep latest operator evidence';
        $section->update(['metadata' => $metadata]);
        $before = $this->protectedState($section->fresh());
        Bus::fake();
        $this->assertSame('stored', app(ProposeTalkType::class)->persist($input, $this->proposal()));
        $after = $section->fresh();
        $this->assertSame($before, $this->protectedState($after));
        $talk = $after->metadata->talkType;
        $this->assertSame(TalkType::ChurchUpdate, $talk->proposed);
        $this->assertSame(TalkType::Testimony, $talk->publicationTalkType());
        $this->assertSame('A series', $talk->proposal()->proposedSeries);
        $this->assertSame('testimony', $talk->raw['structure_proposed']);
        $this->assertSame($input->inputHash(), $talk->raw['input_hash']);
        $this->assertSame(['classifier' => 'b1', 'input_schema' => 1], $talk->raw['versions']);
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function stale_input_result_is_refused(): void
    {
        $section = $this->section();
        $input = app(TalkTypeInputBuilder::class)->build($section, ['classifier' => 'fixture-v1']);
        $section->update(['end_time' => 145, 'duration' => 45]);
        $before = $section->fresh()->getRawOriginal();
        $this->assertSame('stale', app(ProposeTalkType::class)->persist($input, $this->proposal()));
        $this->assertSame($before, $section->fresh()->getRawOriginal());
    }

    #[Test]
    public function identical_input_is_a_no_op(): void
    {
        $section = $this->section();
        $builder = app(TalkTypeInputBuilder::class);
        $input = $builder->build($section, ['classifier' => 'b1']);
        $action = app(ProposeTalkType::class);
        $this->assertSame('stored', $action->persist($input, $this->proposal()));
        $before = $section->fresh()->getRawOriginal();
        $this->travel(5)->minutes();
        $this->assertSame($input->inputHash(), $builder->build($section, ['classifier' => 'b1'])->inputHash());
        $this->assertSame('unchanged', $action->persist($input, $this->proposal()));
        $this->assertSame($before, $section->fresh()->getRawOriginal());
        $next = $builder->build($section, ['classifier' => 'b2']);
        $this->assertSame('stored', $action->persist($next, $this->proposal()));
        $talk = $section->fresh()->metadata->talkType->raw;
        $this->assertCount(1, $talk['proposal_history']);
        $this->assertSame($input->inputHash(), $talk['proposal_history'][0]['input_hash']);
        $this->assertSame('testimony', $talk['structure_proposed']);
    }

    #[Test]
    public function input_uses_full_current_text_and_records_overlap_without_anchoring(): void
    {
        $section = $this->section();
        config(['filesystems.disks.talk_type_test' => ['driver' => 'local', 'root' => storage_path('scratch/talk-type-build-20261115-b1/test-artifacts')], 'media-processing.storage.service_artifact_disk' => 'talk_type_test']);
        $path = 'service-transcripts/input.json';
        Storage::disk('talk_type_test')->put($path, json_encode(['cues' => [
            ['start' => 95, 'end' => 105, 'text' => 'Overlapping opening.'],
            ['start' => 105, 'end' => 140, 'text' => 'All the available middle and ending.'],
        ], 'duration' => 200, 'source' => 'sidecar', 'unobservable_windows' => [['start' => 120, 'end' => 125, 'reason' => 'missing']]], JSON_THROW_ON_ERROR));
        $section->processingLog->update(['processing_metadata' => ['service_transcript_path' => $path]]);
        $input = app(TalkTypeInputBuilder::class)->build($section, ['classifier' => 'fixture-v1']);
        $this->assertSame('Overlapping opening. All the available middle and ending.', $input->evidence['text']);
        $this->assertTrue($input->evidence['transcript']['boundary_overlap']);
        $this->assertCount(1, $input->evidence['transcript']['unobservable_windows']);
        $this->assertStringNotContainsString('testimony', json_encode($input->evidence));
        $this->assertArrayNotHasKey('speaker', $input->evidence);
        $this->assertSame('testimony', $input->structureProposed);
        Storage::disk('talk_type_test')->put($path, json_encode(['cues' => [['start' => 100, 'end' => 140, 'text' => 'Changed stored transcript.']], 'duration' => 200, 'source' => 'sidecar'], JSON_THROW_ON_ERROR));
        $before = $section->fresh()->getRawOriginal();
        $this->assertSame('stale', app(ProposeTalkType::class)->persist($input, $this->proposal()));
        $this->assertSame($before, $section->fresh()->getRawOriginal());
        Storage::disk('talk_type_test')->delete($path);
    }

    #[Test]
    public function changed_item_neighbour_or_run_state_refuses_the_result(): void
    {
        foreach (['item', 'neighbour', 'superseded', 'excluded', 'missing', 'bounds', 'type', 'text'] as $change) {
            $section = $this->section();
            $neighbour = ServiceSection::factory()->create(['media_processing_log_id' => $section->media_processing_log_id, 'start_time' => 150, 'end_time' => 250]);
            $input = app(TalkTypeInputBuilder::class)->build($section, ['classifier' => 'fixture-v1']);
            match ($change) {
                'item' => $section->churchServiceItem->update(['title' => 'Changed current title']),
                'neighbour' => $neighbour->update(['title' => 'Changed role evidence']),
                'superseded' => $section->processingLog->update(['superseded_at' => now()]),
                'missing' => $section->delete(),
                'excluded' => $section->processingLog->update(['processing_metadata' => ['exclusion' => ['reason' => 'no_sermon_in_source']]]),
                'bounds' => $section->update(['end_time' => 145, 'duration' => 45]),
                'type' => $section->update(['section_type' => ServiceSectionType::Welcome]),
                'text' => $section->update(['metadata' => array_replace($section->metadata->toArray(), ['transcript' => 'New evidence'])]),
            };
            $before = $section->fresh()?->getRawOriginal();
            $this->assertContains(app(ProposeTalkType::class)->persist($input, $this->proposal()), ['stale', 'ineligible']);
            $this->assertSame($before, $section->fresh()?->getRawOriginal());
        }
    }

    #[Test]
    public function held_and_deleted_item_evidence_is_explicit_and_a_null_result_never_confirms(): void
    {
        $section = $this->section();
        $section->churchServiceItem->delete();
        $input = app(TalkTypeInputBuilder::class)->build($section, ['classifier' => 'fixture-v1']);
        $this->assertTrue($input->evidence['item']['deleted']);
        $this->assertSame($section->metadata->raw['content_holds'], $input->evidence['held_state']['content_holds']);
        $before = $this->protectedState($section->fresh());
        $result = new TalkTypeProposal(disposition: 'none_of_these', reasons: ['No supported type'], decidedBy: 'model');
        $this->assertSame('stored', app(ProposeTalkType::class)->persist($input, $result));
        $this->assertNull($section->fresh()->metadata->talkType->proposed);
        $this->assertSame($before, $this->protectedState($section->fresh()));
    }

    #[Test]
    public function proposal_on_a_published_section_preserves_every_other_record(): void
    {
        $section = $this->section();
        $section->update(['publication_status' => 'published', 'published_sermon_id' => Sermon::factory()->create()->id, 'published_at' => now()]);
        $before = $this->protectedState($section->fresh());
        $input = app(TalkTypeInputBuilder::class)->build($section, ['classifier' => 'fixture-v1']);
        $this->assertSame('stored', app(ProposeTalkType::class)->persist($input, $this->proposal()));
        $this->assertSame($before, $this->protectedState($section->fresh()));
    }

    #[Test]
    public function proposal_without_classifier_version_is_refused_without_writing(): void
    {
        $section = $this->section();
        $before = $section->fresh()->getRawOriginal();
        $input = app(TalkTypeInputBuilder::class)->build($section);
        $this->assertSame('ineligible', app(ProposeTalkType::class)->persist($input, $this->proposal()));
        $this->assertSame($before, $section->fresh()->getRawOriginal());
    }

    #[Test]
    public function persist_stores_an_automatic_confirmation_and_leaves_an_operator_answer(): void
    {
        $section = $this->section();
        $metadata = $section->metadata->toArray();
        unset($metadata['talk_type']['reviewed']);
        $section->update(['metadata' => $metadata]);
        $input = app(TalkTypeInputBuilder::class)->build($section->fresh(), ['classifier' => 'b1']);
        $proposal = new TalkTypeProposal(proposed: TalkType::ChildrensTalk, disposition: 'proposed', confidence: 0.98, decidedBy: 'model');

        $this->assertSame('stored', app(ProposeTalkType::class)->persist($input, $proposal));

        $reviewed = $section->fresh()->metadata->talkType->reviewed;
        $this->assertSame('childrens_talk', $reviewed['value']);
        $this->assertSame('automatic', $reviewed['source']);
        $this->assertSame(TalkType::ChildrensTalk, $section->fresh()->publicationTalkType());

        $operator = $this->section();
        $operatorInput = app(TalkTypeInputBuilder::class)->build($operator, ['classifier' => 'b1']);
        app(ProposeTalkType::class)->persist($operatorInput, $proposal);
        $this->assertSame('partner_update', $operator->fresh()->metadata->talkType->reviewed['value']);
    }

    #[Test]
    public function persist_withholds_a_not_a_talk_proposal_and_demotes_a_changed_one(): void
    {
        $section = $this->section();
        $metadata = $section->metadata->toArray();
        unset($metadata['talk_type']['reviewed']);
        $section->update(['metadata' => $metadata]);
        $action = app(ProposeTalkType::class);
        $input = app(TalkTypeInputBuilder::class)->build($section->fresh(), ['classifier' => 'b1']);
        $action->persist($input, new TalkTypeProposal(disposition: 'not_a_talk', decidedBy: 'model', confidence: null));
        $this->assertTrue($section->fresh()->metadata->talkType->isWithheld());

        $next = app(TalkTypeInputBuilder::class)->build($section->fresh(), ['classifier' => 'b2']);
        $action->persist($next, new TalkTypeProposal(proposed: TalkType::PartnerUpdate, disposition: 'proposed', confidence: 0.99, decidedBy: 'model'));
        $this->assertNull($section->fresh()->metadata->talkType->reviewed);
    }

    private function section(): ServiceSection
    {
        return ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ShortTalk,
            'title' => 'Current talk', 'start_time' => 100, 'end_time' => 140,
            'publication_status' => 'approved', 'needs_manual_review' => true,
            'metadata' => [
                'transcript' => 'Existing excerpt.',
                'talk_type' => ['proposed' => 'testimony', 'reviewed' => ['value' => 'partner_update'], 'unknown' => ['keep' => true]],
                'talk_speaker' => ['reviewed' => ['preacher_name' => 'Reviewed speaker', 'source' => 'manual']],
                'publication' => ['approved_signature' => 'kept', 'hold' => 'operator hold'],
                'publication_candidate_extraction' => ['signature' => 'candidate', 'segments' => [['start' => 101, 'end' => 140]], 'video_path' => 'existing.mp4'],
                'content_holds' => [['reason' => 'unobservable', 'evidence' => 'fixture.json', 'raised_by' => 1]],
                'output_edge_answers' => ['start' => ['time' => 101, 'evidence' => 'reviewed']],
                'short_talk_boundary' => ['reviewed_recuts' => [['end_time' => 140]]],
                'review_flags' => ['held'], 'unknown' => ['nested' => [1, 2]],
            ],
        ]);
    }

    private function proposal(): TalkTypeProposal
    {
        return new TalkTypeProposal(proposed: TalkType::ChurchUpdate, proposedSeries: ' A series ', disposition: 'proposed', confidence: 0.8, reasons: [['evidence' => 'whole text']], decidedBy: 'rule', ruleId: 'fixture', provenance: ['schema_version' => '1'], signals: ['testimony_risk' => true]);
    }

    /** @return array<string, mixed> */
    private function protectedState(ServiceSection $section): array
    {
        $attributes = $section->getRawOriginal();
        unset($attributes['metadata'], $attributes['updated_at']);
        $metadata = $section->metadata->toArray();
        $talk = $metadata['talk_type'];
        unset($metadata['talk_type']);

        return ['run' => $section->processingLog->getRawOriginal(), 'item' => $section->churchServiceItem?->getRawOriginal(), 'sermon' => $section->publishedSermon?->getRawOriginal(), 'attributes' => $attributes, 'metadata' => $metadata,
            'reviewed' => $talk['reviewed'], 'unknown_talk' => $talk['unknown'],
            'media_payload' => $section->mediaSignaturePayload(), 'media_signature' => $section->mediaSignature(),
            'approval_payload' => $section->classificationSignaturePayload(), 'approval_signature' => $section->classificationSignature()];
    }
}
