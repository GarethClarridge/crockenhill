<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosArchiveEntry;
use App\Services\Email\FreezeOosSemanticEvaluationCorpus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FreezeOosSemanticEvaluationCorpusTest extends TestCase
{
    #[Test]
    public function it_freezes_private_sources_and_never_promotes_legacy_output_to_truth(): void
    {
        $artifact = (new FreezeOosSemanticEvaluationCorpus)->build(
            [$this->entry('sample'), $this->entry('hard')],
            $this->legacy(['sample', 'hard']),
            $this->stability(['sample']),
            $this->itemTruth(),
            ['hard'],
        );

        $this->assertSame(FreezeOosSemanticEvaluationCorpus::Format, $artifact['format']);
        $this->assertSame(['sample', 'hard'], $artifact['selection']['selected_source_keys']);
        $this->assertFalse($artifact['completeness']['scoreable']);
        $this->assertSame(2, $artifact['completeness']['pending_sources']);
        $this->assertSame('pending', $artifact['sources'][0]['truth']['adjudication_state']);
        $this->assertNull($artifact['sources'][0]['truth']['annotations']);
        $this->assertTrue($artifact['sources'][0]['legacy_machine_prefill']['not_truth']);
        $this->assertSame('review_required', $artifact['sources'][0]['legacy_machine_prefill']['routing']['category']);
        $this->assertSame('service_boundary', $artifact['sources'][0]['legacy_machine_prefill']['semantic_worksheet']['annotations'][0]['role']);
        $this->assertSame('item', $artifact['sources'][0]['legacy_machine_prefill']['semantic_worksheet']['annotations'][1]['role']);
        $this->assertSame('song', $artifact['sources'][0]['legacy_machine_prefill']['semantic_worksheet']['annotations'][1]['item_kind']);
        $this->assertSame([], $artifact['sources'][0]['legacy_machine_prefill']['semantic_worksheet']['unresolved_line_ids']);
        $this->assertSame('match', $artifact['sources'][0]['authority']['item_truth']['verdicts']['song_membership']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact['corpus_hash']);
    }

    #[Test]
    public function it_refuses_baseline_artifacts_from_different_manifests(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('do not share one curation manifest hash');

        (new FreezeOosSemanticEvaluationCorpus)->build(
            [$this->entry('sample')],
            $this->legacy(['sample']),
            ['manifest_hash' => str_repeat('b', 64), 'stability' => ['sample_source_keys' => ['sample']]],
            $this->itemTruth(),
        );
    }

    #[Test]
    public function it_refuses_an_unknown_hard_case_instead_of_silently_shrinking_the_corpus(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing is not an approved corpus entry');

        (new FreezeOosSemanticEvaluationCorpus)->build(
            [$this->entry('sample')],
            $this->legacy(['sample']),
            $this->stability(['sample']),
            $this->itemTruth(),
            ['missing'],
        );
    }

    private function entry(string $key): OosArchiveEntry
    {
        return new OosArchiveEntry(
            index: 1,
            itemKey: $key,
            subject: 'Order of service',
            bodyPlain: "Morning service\nSong One",
            groundTruthDate: '2026-08-23',
            contentScope: 'full',
            servicesPresent: ['morning'],
            itemLineCounts: [],
            curation: [],
            syntheticMessageId: "<{$key}@example.test>",
            sourceKey: "{$key}|morning:2026-08-23",
            supersedesSourceKey: null,
            inputHash: hash('sha256', $key),
            syntheticReceivedAt: CarbonImmutable::parse('2026-08-21'),
        );
    }

    /** @param list<string> $keys @return array<string, mixed> */
    private function legacy(array $keys): array
    {
        return [
            'manifest_hash' => str_repeat('a', 64),
            'raw_results' => array_map(static fn (string $key): array => [
                'source_key' => $key,
                'routing' => ['category' => 'review_required'],
                'raw_result' => ['service_plans' => [[
                    'service' => 'morning',
                    'items' => [[
                        'position' => 1,
                        'section_type' => 'song',
                    ]],
                    'source_provenance' => [
                        'service_evidence_line_ids' => [1],
                        'items' => [[
                            'position' => 1,
                            'source_line_ids' => [2],
                        ]],
                    ],
                ]]],
                'telemetry' => [['usage' => ['total_tokens' => 10]]],
            ], $keys),
        ];
    }

    /** @param list<string> $keys @return array<string, mixed> */
    private function stability(array $keys): array
    {
        return [
            'manifest_hash' => str_repeat('a', 64),
            'stability' => ['sample_source_keys' => $keys],
        ];
    }

    /** @return array<string, mixed> */
    private function itemTruth(): array
    {
        return [
            'format' => 'historic-item-ground-truth',
            'identities' => [[
                'date' => '2026-08-23',
                'service' => 'morning',
                'verdicts' => ['song_membership' => 'match'],
            ]],
        ];
    }
}
