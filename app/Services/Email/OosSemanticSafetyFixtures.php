<?php

declare(strict_types=1);

namespace App\Services\Email;

/**
 * Synthetic sources and deliberately defective parser output, one per failure family §6.3 names.
 *
 * These are wholly invented orders of service. No private email body is committed, and nothing here
 * is derived from the private corpus: a fixture exists to prove that a *defect* is refused, and a
 * defect that only occurs in real mail would be untestable without shipping the mail.
 *
 * Two layers, because the candidate has two independent places to fail closed:
 *
 * - `annotation` fixtures hand the candidate a defective annotation set. They must be refused by
 *   {@see OosSemanticAnnotationValidator} *before* {@see CompileOosSemanticAnnotations} runs, so
 *   nothing is compiled at all.
 * - `extraction` fixtures bypass the annotator entirely and hand the legacy compatibility path an
 *   already-compiled extraction that violates a line-accounting rule. They prove the downstream
 *   safety net named in gate 4 still holds such a plan even if a future compiler bug emitted one.
 *
 * Every `extraction` fixture declares `confidence: 1.0` and a resolvable Sunday identity on purpose.
 * A fixture held only because the compiler's fixed 0.75 confidence sits under the 0.90 auto-import
 * threshold would prove nothing about the content rules; at 1.0 the *only* thing that can hold it is
 * the rule under test. The clean control fixture is the other half of that argument: it must come
 * back auto-importable, or the harness is refusing everything for a reason unrelated to safety.
 */
class OosSemanticSafetyFixtures
{
    public const string Format = 'crockenhill-oos-semantic-safety-fixtures';

    public const int Version = 1;

    /** Expectation: the annotation validator refuses the source before any plan is compiled. */
    public const string ExpectRefusedBeforeCompilation = 'refused_before_compilation';

    /** Expectation: a content rule fires, so the plan is invalid and importable by nobody. */
    public const string ExpectContentInvalid = 'content_invalid';

    /** Expectation: a bookkeeping rule fires, so the plan is held for a human but not rejected. */
    public const string ExpectHeldForReview = 'held_for_review';

    /** Control: a clean, confident, identifiable plan must remain auto-importable. */
    public const string ExpectAutoImportable = 'auto_importable';

    /**
     * A Sunday far enough out that no real import can exist for it, so the duplicate-import half of
     * date plausibility cannot quietly hold a fixture for a reason the fixture is not about.
     */
    private const FixtureDate = '2099-01-04';

    private const Subject = 'Order of service for Sunday 4 January 2099';

    private const Body = "Morning Service - Sunday 4 January 2099\n"
        ."Hymn 100 Praise the Lord\n"
        ."Bible Reading: John 1\n"
        ."Sermon: The Word became flesh\n"
        .'Best wishes';

    /**
     * @return list<array{name:string,family:string,layer:string,expectation:string,subject:string,body:string,received_date:string,annotation:?array<string,mixed>,extraction:?array<string,mixed>}>
     */
    public function all(): array
    {
        return [
            ...$this->annotationFixtures(),
            ...$this->extractionFixtures(),
        ];
    }

    /**
     * Defects the annotation validator owns. Each must stop the parse before compilation, which is
     * why every one of them is expected to refuse rather than merely to hold.
     *
     * @return list<array{name:string,family:string,layer:string,expectation:string,subject:string,body:string,received_date:string,annotation:array<string,mixed>,extraction:null}>
     */
    private function annotationFixtures(): array
    {
        return [
            $this->annotationFixture(
                'annotation_missing_line',
                'missing_line_identity',
                $this->annotationSet(omitLineIds: [5]),
            ),
            $this->annotationFixture(
                'annotation_invented_line',
                'invented_line_identity',
                $this->annotationSet(extraAnnotations: [
                    $this->line(99, 'notice_context'),
                ]),
            ),
            $this->annotationFixture(
                'annotation_duplicate_line_identity',
                'duplicate_line_identity',
                // One response slot carrying a different line's identity. The schema cannot express
                // two entries for the same line, so a duplicate reaches the validator as a key that
                // disagrees with the line inside it.
                $this->annotationSet(replace: [5 => $this->line(5, 'greeting_or_signature', lineIdOverride: 4)]),
            ),
            $this->annotationFixture(
                'continuation_not_adjacent',
                'continuation_adjacency',
                $this->annotationSet(replace: [
                    4 => $this->line(4, 'continuation', group: 'morning', continuationTarget: 2),
                ]),
            ),
            $this->annotationFixture(
                'continuation_targets_non_item',
                'continuation_target',
                // Line 5 continues line 4 only if line 4 is an item or a continuation. Here line 4 is
                // supporting detail, which is the wrapped-notice case that must be annotated twice
                // rather than joined.
                $this->annotationSet(replace: [
                    4 => $this->line(4, 'supporting_detail', group: 'morning'),
                    5 => $this->line(5, 'continuation', group: 'morning', continuationTarget: 4),
                ]),
            ),
            $this->annotationFixture(
                'unknown_service_group',
                'service_group_reference',
                $this->annotationSet(replace: [
                    3 => $this->line(3, 'item', group: 'evening', kind: 'bible_reading'),
                ]),
            ),
            $this->annotationFixture(
                'item_without_semantic_kind',
                'item_semantics',
                $this->annotationSet(replace: [
                    2 => $this->line(2, 'item', group: 'morning'),
                ]),
            ),
            $this->annotationFixture(
                'boundary_evidence_not_annotated',
                'service_boundary_evidence',
                $this->annotationSet(
                    services: [$this->service('morning', 'morning', [1, 3])],
                ),
            ),
        ];
    }

    /**
     * Already-compiled extractions that violate a line-accounting rule, at full confidence.
     *
     * @return list<array{name:string,family:string,layer:string,expectation:string,subject:string,body:string,received_date:string,annotation:null,extraction:array<string,mixed>}>
     */
    private function extractionFixtures(): array
    {
        return [
            $this->extractionFixture(
                'clean_control',
                'none',
                self::ExpectAutoImportable,
                $this->extraction($this->cleanItems()),
            ),
            $this->extractionFixture(
                'items_out_of_source_order',
                'items_out_of_source_order',
                self::ExpectContentInvalid,
                $this->extraction([
                    $this->item('sermon', 'Sermon: The Word became flesh', [4]),
                    $this->item('song', 'Hymn 100 Praise the Lord', [2]),
                    $this->item('bible_reading', 'Bible Reading: John 1', [3]),
                ]),
            ),
            $this->extractionFixture(
                'source_line_claimed_by_multiple_items',
                'source_line_claimed_by_multiple_items',
                self::ExpectContentInvalid,
                $this->extraction([
                    $this->item('song', 'Hymn 100 Praise the Lord', [2]),
                    $this->item('bible_reading', 'Bible Reading: John 1', [3]),
                    $this->item('sermon', 'Bible Reading: John 1', [3]),
                    $this->item('other', 'Sermon: The Word became flesh', [4]),
                ]),
            ),
            $this->extractionFixture(
                'item_merges_non_continuation_lines',
                'item_merges_non_continuation_lines',
                self::ExpectContentInvalid,
                $this->extraction([
                    $this->item('song', 'Hymn 100 Praise the Lord Sermon: The Word became flesh', [2, 4]),
                    $this->item('bible_reading', 'Bible Reading: John 1', [3]),
                ]),
            ),
            $this->extractionFixture(
                'item_source_line_missing',
                'phantom_source_line',
                self::ExpectContentInvalid,
                $this->extraction([
                    $this->item('song', 'Hymn 100 Praise the Lord', [2]),
                    $this->item('bible_reading', 'Bible Reading: John 1', [3]),
                    $this->item('sermon', 'Sermon: The Word became flesh', [4]),
                    $this->item('other', 'A line that does not exist', [42]),
                ]),
            ),
            $this->extractionFixture(
                'plan_without_items',
                'no_items',
                self::ExpectContentInvalid,
                $this->extraction([], ignoredLineIds: [2, 3, 4, 5]),
            ),
            $this->extractionFixture(
                'line_ignored_and_claimed',
                'line_ignored_and_claimed',
                self::ExpectHeldForReview,
                $this->extraction($this->cleanItems(), ignoredLineIds: [3, 5]),
            ),
            $this->extractionFixture(
                'line_ignored_inside_item_span',
                'line_ignored_inside_item_span',
                self::ExpectHeldForReview,
                // Line 3 reads as a service item and sits between two extracted items, so ignoring it
                // may be a dropped item rather than an aside.
                $this->extraction([
                    $this->item('song', 'Hymn 100 Praise the Lord', [2]),
                    $this->item('sermon', 'Sermon: The Word became flesh', [4]),
                ], ignoredLineIds: [3, 5]),
            ),
            $this->extractionFixture(
                'line_unclassified',
                'line_unclassified',
                self::ExpectHeldForReview,
                $this->extraction($this->cleanItems(), ignoredLineIds: []),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $annotation
     * @return array{name:string,family:string,layer:string,expectation:string,subject:string,body:string,received_date:string,annotation:array<string,mixed>,extraction:null}
     */
    private function annotationFixture(string $name, string $family, array $annotation): array
    {
        return [
            'name' => $name,
            'family' => $family,
            'layer' => 'annotation',
            'expectation' => self::ExpectRefusedBeforeCompilation,
            'subject' => self::Subject,
            'body' => self::Body,
            'received_date' => '2099-01-02',
            'annotation' => $annotation,
            'extraction' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $extraction
     * @return array{name:string,family:string,layer:string,expectation:string,subject:string,body:string,received_date:string,annotation:null,extraction:array<string,mixed>}
     */
    private function extractionFixture(string $name, string $family, string $expectation, array $extraction): array
    {
        return [
            'name' => $name,
            'family' => $family,
            'layer' => 'extraction',
            'expectation' => $expectation,
            'subject' => self::Subject,
            'body' => self::Body,
            'received_date' => '2099-01-02',
            'annotation' => null,
            'extraction' => $extraction,
        ];
    }

    /**
     * The valid annotation of the fixture body, with one defect injected by the caller.
     *
     * @param  list<int>  $omitLineIds
     * @param  array<int, array<string, mixed>>  $replace
     * @param  list<array<string, mixed>>  $extraAnnotations
     * @param  ?list<array<string, mixed>>  $services
     * @return array<string, mixed>
     */
    private function annotationSet(
        array $omitLineIds = [],
        array $replace = [],
        array $extraAnnotations = [],
        ?array $services = null,
    ): array {
        $annotations = [
            1 => $this->line(1, 'service_boundary', group: 'morning'),
            2 => $this->line(2, 'item', group: 'morning', kind: 'song'),
            3 => $this->line(3, 'item', group: 'morning', kind: 'bible_reading'),
            4 => $this->line(4, 'item', group: 'morning', kind: 'sermon'),
            5 => $this->line(5, 'greeting_or_signature'),
        ];

        foreach ($omitLineIds as $lineId) {
            unset($annotations[$lineId]);
        }

        foreach ($replace as $lineId => $annotation) {
            $annotations[$lineId] = $annotation;
        }

        return [
            'services' => $services ?? [$this->service('morning', 'morning', [1])],
            'annotations' => [...array_values($annotations), ...$extraAnnotations],
        ];
    }

    /**
     * @param  list<int>  $boundaryLineIds
     * @return array<string, mixed>
     */
    private function service(string $groupId, ?string $proposedService, array $boundaryLineIds): array
    {
        return [
            'group_id' => $groupId,
            'proposed_service' => $proposedService,
            'boundary_line_ids' => $boundaryLineIds,
            'uncertainties' => [],
        ];
    }

    /**
     * `key` is the response slot and `line_id` the identity inside it. They are separate so a
     * fixture can make them disagree, which is how a duplicate line identity presents.
     *
     * @return array<string, mixed>
     */
    private function line(
        int $key,
        string $role,
        ?string $group = null,
        ?string $kind = null,
        ?int $continuationTarget = null,
        ?int $lineIdOverride = null,
    ): array {
        return [
            'key' => $key,
            'line_id' => $lineIdOverride ?? $key,
            'role' => $role,
            'service_group_id' => $group,
            'item_kind' => $kind,
            'continuation_target_line_id' => $continuationTarget,
            'uncertainty' => null,
            'shared_service_group_ids' => [],
            'boundary_also_item' => false,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function cleanItems(): array
    {
        return [
            $this->item('song', 'Hymn 100 Praise the Lord', [2]),
            $this->item('bible_reading', 'Bible Reading: John 1', [3]),
            $this->item('sermon', 'Sermon: The Word became flesh', [4]),
        ];
    }

    /**
     * @param  list<int>  $sourceLineIds
     * @return array<string, mixed>
     */
    private function item(string $type, string $title, array $sourceLineIds): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'source_line_ids' => $sourceLineIds,
            'continuation' => false,
            'semantic_kind' => $type,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $ignoredLineIds
     * @return array<string, mixed>
     */
    private function extraction(array $items, ?array $ignoredLineIds = null): array
    {
        $claimed = [1];

        foreach ($items as $item) {
            $claimed = [...$claimed, ...$item['source_line_ids']];
        }

        $ignoredLineIds ??= array_values(array_diff([1, 2, 3, 4, 5], $claimed));

        return [
            'items' => $items,
            // Full confidence on purpose: only the rule under test may hold this plan.
            'confidence' => 1.0,
            'services' => [[
                'service' => 'morning',
                'date' => self::FixtureDate,
                'content_scope' => 'full',
                'service_evidence_line_ids' => [1],
                'items' => $items,
                'confidence' => 1.0,
            ]],
            'ignored_lines' => array_map(
                static fn (int $lineId): array => ['line_id' => $lineId, 'reason' => 'context'],
                $ignoredLineIds,
            ),
        ];
    }
}
