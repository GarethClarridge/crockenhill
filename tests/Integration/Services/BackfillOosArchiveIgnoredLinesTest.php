<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;
use App\Models\InboundEmail;
use App\Services\Email\BackfillOosArchiveIgnoredLines;
use App\Services\Email\CompileOosSemanticAnnotations;
use App\Services\Email\OosArchiveParseCacheBinding;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillOosArchiveIgnoredLinesTest extends TestCase
{
    use RefreshDatabase;

    private BackfillOosArchiveIgnoredLines $backfill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backfill = app(BackfillOosArchiveIgnoredLines::class);
    }

    #[Test]
    public function it_recovers_ignored_lines_into_both_the_parse_and_the_cache(): void
    {
        $email = $this->archiveSource();

        $result = $this->backfill->backfill(apply: true);

        $this->assertSame(1, $result['examined']);
        $this->assertSame(1, $result['backfilled']);
        $this->assertSame(2, $result['lines']);
        $this->assertSame([], $result['skipped']);

        $metadata = $email->fresh()->processing_metadata;
        $expected = [
            ['line_id' => 1, 'reason' => 'forwarded_header'],
            ['line_id' => 5, 'reason' => 'signature'],
        ];

        // assertEquals, not assertSame: MySQL's JSON type normalises object keys by length, so a
        // value read back carries the same keys in a different order.
        $this->assertEquals($expected, Arr::get($metadata, 'parsing.ignored_lines'));
        // Both halves, or the next --cache-only replay decodes the stale payload and silently
        // undoes the backfill.
        $this->assertEquals($expected, Arr::get($metadata, 'archive_parse_cache.raw_result.ignored_lines'));
    }

    /**
     * A hash that no longer describes its own payload verifies nothing while looking like it does,
     * which is worse than carrying no hash at all.
     */
    #[Test]
    public function it_recomputes_the_raw_result_hash_over_the_payload_it_wrote(): void
    {
        $email = $this->archiveSource();
        $before = Arr::get($email->processing_metadata, 'archive_parse_cache.raw_result_hash');

        $this->backfill->backfill(apply: true);

        $metadata = $email->fresh()->processing_metadata;
        $after = Arr::get($metadata, 'archive_parse_cache.raw_result_hash');

        $this->assertNotSame($before, $after);
        $this->assertSame(
            CanonicalJson::hash(Arr::get($metadata, 'archive_parse_cache.raw_result')),
            $after,
        );
    }

    /**
     * The property the extraction exists to guarantee. If the backfill held its own copy of the
     * rule the two would agree on today's corpus and drift later, and the drift would surface as
     * the validator blaming a document for an unclassified line.
     */
    #[Test]
    public function it_recovers_exactly_what_the_compiler_would_have_produced(): void
    {
        $email = $this->archiveSource();
        $annotations = OosSemanticAnnotationResult::fromArray($this->annotations());

        $this->backfill->backfill(apply: true);

        $compiled = app(CompileOosSemanticAnnotations::class)
            ->compile($this->document(), $annotations)
            ->extraction
            ->ignoredLines;

        $this->assertEquals($compiled, Arr::get($email->fresh()->processing_metadata, 'parsing.ignored_lines'));
    }

    #[Test]
    public function a_dry_run_reports_without_writing(): void
    {
        $email = $this->archiveSource();

        $result = $this->backfill->backfill(apply: false);

        $this->assertSame(1, $result['backfilled']);
        $this->assertNull(Arr::get($email->fresh()->processing_metadata, 'parsing.ignored_lines'));
    }

    #[Test]
    public function a_source_that_already_carries_ignored_lines_is_left_alone(): void
    {
        $this->archiveSource();
        $this->backfill->backfill(apply: true);

        $second = $this->backfill->backfill(apply: true);

        $this->assertSame(1, $second['examined']);
        $this->assertSame(1, $second['already_present']);
        $this->assertSame(0, $second['backfilled']);
    }

    /**
     * Never guessed at. Inferring "ignored = every line no item claimed" would satisfy the
     * validator's coverage rule with no evidence the model ever saw those lines — a silent pass in
     * place of an honest refusal.
     */
    #[Test]
    public function a_source_whose_annotations_cannot_be_replayed_is_skipped_and_named(): void
    {
        $email = $this->archiveSource();
        $metadata = $email->processing_metadata;
        Arr::set($metadata, 'archive_parse_cache.raw_result.extraction_attempts.0.selected', false);
        $email->processing_metadata = $metadata;
        $email->save();

        $result = $this->backfill->backfill(apply: true);

        $this->assertSame(0, $result['backfilled']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame('<oos-2026-07-12-am@crockenhill.local>', $result['skipped'][0]['message_id']);
        $this->assertStringContainsString('0 selected attempts', $result['skipped'][0]['reason']);
        $this->assertNull(Arr::get($email->fresh()->processing_metadata, 'parsing.ignored_lines'));
    }

    #[Test]
    public function a_source_with_no_archive_parse_cache_is_not_examined(): void
    {
        InboundEmail::factory()->create(['processing_metadata' => ['parsing' => ['items' => []]]]);

        $result = $this->backfill->backfill(apply: true);

        $this->assertSame(0, $result['examined']);
    }

    private function document(): OosEmailSourceDocument
    {
        return OosEmailSourceDocument::fromContext(
            'Order for Sunday 12 July 2026',
            "From: Jon\nMorning Service\nAmazing Grace\nSermon: Romans 8\nEvery blessing, Jon",
            '2026-07-08',
        );
    }

    /**
     * Line 1 is forwarded context and line 5 a signature, both outside every service group, so
     * both are ignored lines. Lines 2-4 belong to the group and are the service's boundary and
     * items.
     *
     * @return array<string, mixed>
     */
    private function annotations(): array
    {
        $annotation = static fn (int $lineId, string $role, ?string $group, ?string $kind = null): array => [
            'line_id' => $lineId,
            'role' => $role,
            'item_kind' => $kind,
            'uncertainty' => null,
            'service_group_id' => $group,
            'boundary_also_item' => false,
            'shared_service_group_ids' => [],
            'continuation_target_line_id' => null,
        ];

        return [
            'format_version' => 1,
            'services' => [[
                'group_id' => 'G1',
                'proposed_service' => 'morning',
                'boundary_line_ids' => [2],
                'uncertainties' => [],
            ]],
            'annotations' => [
                $annotation(1, 'forwarded_context', null),
                $annotation(2, 'service_boundary', 'G1'),
                $annotation(3, 'item', 'G1', 'song'),
                $annotation(4, 'item', 'G1', 'sermon'),
                $annotation(5, 'greeting_or_signature', null),
            ],
        ];
    }

    private function archiveSource(): InboundEmail
    {
        $rawResult = [
            'items' => [],
            'resolved_date' => '2026-07-12',
            'resolved_service' => 'morning',
            'needs_review' => false,
            'should_import' => true,
            'extraction_attempts' => [[
                'attempt' => 1,
                'selected' => true,
                'final_annotations' => $this->annotations(),
            ]],
        ];

        return InboundEmail::factory()->create([
            'message_id' => '<oos-2026-07-12-am@crockenhill.local>',
            'processing_metadata' => [
                'parsing' => $rawResult,
                OosArchiveParseCacheBinding::MetadataKey => [
                    'version' => OosArchiveParseCacheBinding::Version,
                    'raw_result' => $rawResult,
                    'raw_result_hash' => CanonicalJson::hash($rawResult),
                ],
            ],
        ]);
    }
}
