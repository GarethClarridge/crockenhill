<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosEmailSourceDocument;
use App\Exceptions\OosSemanticCompilationException;
use App\Services\Email\AdjudicateOosSemanticEvaluationCorpus;
use App\Services\Email\CompileOosSemanticAnnotations;
use App\Services\Email\FreezeOosSemanticEvaluationCorpus;
use App\Services\Email\OosSemanticAnnotationDecoder;
use App\Services\Email\OosSemanticAnnotationSchema;
use App\Services\Email\OosSemanticAnnotationValidator;
use App\Services\Email\OosServiceDateResolver;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AdjudicateOosSemanticEvaluationCorpusTest extends TestCase
{
    #[Test]
    public function it_adjudicates_a_decided_source_and_leaves_an_undecided_one_pending(): void
    {
        $corpus = $this->corpus();

        $artifact = $this->adjudicator()->build($corpus, ['sample' => $this->decision()]);

        $this->assertSame(1, $artifact['completeness']['fully_adjudicated_sources']);
        $this->assertSame(1, $artifact['completeness']['pending_sources']);
        $this->assertFalse($artifact['completeness']['scoreable']);

        $sample = $artifact['sources'][0];
        $this->assertSame('adjudicated', $sample['truth']['adjudication_state']);
        $this->assertSame('maintainer@example.test', $sample['truth']['adjudicated_by']);
        $this->assertCount(2, $sample['truth']['annotations']);
        $this->assertSame('service_boundary', $sample['truth']['annotations'][0]['role']);
        $this->assertSame('item', $sample['truth']['annotations'][1]['role']);
        $this->assertSame('morning', $sample['truth']['expected_plans'][0]['service']);
        $this->assertSame('song', $sample['truth']['expected_plans'][0]['items'][0]['type']);
        $this->assertSame([2], $sample['truth']['expected_plans'][0]['items'][0]['source_line_ids']);

        $hard = $artifact['sources'][1];
        $this->assertSame('pending', $hard['truth']['adjudication_state']);
        $this->assertNull($hard['truth']['annotations']);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact['corpus_hash']);
        $this->assertNotSame($corpus['corpus_hash'], $artifact['corpus_hash']);
    }

    #[Test]
    public function it_becomes_scoreable_only_once_every_source_is_decided(): void
    {
        $artifact = $this->adjudicator()->build($this->corpus(), [
            'sample' => $this->decision(),
            'hard' => $this->decision(),
        ]);

        $this->assertTrue($artifact['completeness']['scoreable']);
        $this->assertSame(0, $artifact['completeness']['pending_sources']);
        $this->assertSame([], $artifact['completeness']['blocking_fields']);
    }

    #[Test]
    public function it_refuses_a_decision_for_a_source_outside_the_frozen_corpus(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('references unknown source ghost');

        $this->adjudicator()->build($this->corpus(), ['ghost' => $this->decision()]);
    }

    #[Test]
    public function it_refuses_a_decision_without_an_adjudicator_identity(): void
    {
        $decision = $this->decision();
        unset($decision['adjudicated_by']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no adjudicator identity');

        $this->adjudicator()->build($this->corpus(), ['sample' => $decision]);
    }

    #[Test]
    public function it_refuses_a_decision_that_fails_deterministic_compilation(): void
    {
        $decision = $this->decision();
        $decision['annotations']['L002']['role'] = 'continuation';
        $decision['annotations']['L002']['continuation_target_line_id'] = 1;

        $this->expectException(OosSemanticCompilationException::class);

        $this->adjudicator()->build($this->corpus(), ['sample' => $decision]);
    }

    #[Test]
    public function it_refuses_a_tampered_frozen_corpus(): void
    {
        $corpus = $this->corpus();
        $corpus['selection']['note'] = 'tampered after freeze';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match its contents');

        $this->adjudicator()->build($corpus, []);
    }

    private function adjudicator(): AdjudicateOosSemanticEvaluationCorpus
    {
        $schema = new OosSemanticAnnotationSchema;
        $validator = new OosSemanticAnnotationValidator;

        return new AdjudicateOosSemanticEvaluationCorpus(
            new OosSemanticAnnotationDecoder($schema),
            new CompileOosSemanticAnnotations($validator, new OosServiceDateResolver),
        );
    }

    /** @return array<string, mixed> */
    private function decision(): array
    {
        return [
            'services' => [[
                'group_id' => 'morning',
                'proposed_service' => 'morning',
                'boundary_line_ids' => [1],
                'uncertainties' => [],
            ]],
            'annotations' => [
                'L001' => [
                    'role' => 'service_boundary',
                    'service_group_id' => 'morning',
                    'item_kind' => null,
                    'continuation_target_line_id' => null,
                    'uncertainty' => null,
                    'shared_service_group_ids' => [],
                    'boundary_also_item' => false,
                ],
                'L002' => [
                    'role' => 'item',
                    'service_group_id' => 'morning',
                    'item_kind' => 'song',
                    'continuation_target_line_id' => null,
                    'uncertainty' => null,
                    'shared_service_group_ids' => [],
                    'boundary_also_item' => false,
                ],
            ],
            'adjudicated_by' => 'maintainer@example.test',
            'adjudicated_at' => '2026-08-19T12:00:00+01:00',
        ];
    }

    /** @return array<string, mixed> */
    private function corpus(): array
    {
        $corpus = [
            'format' => FreezeOosSemanticEvaluationCorpus::Format,
            'version' => FreezeOosSemanticEvaluationCorpus::Version,
            'private' => true,
            'selection' => ['selected_source_keys' => ['sample', 'hard']],
            'completeness' => [
                'source_count' => 2,
                'fully_adjudicated_sources' => 0,
                'pending_sources' => 2,
                'scoreable' => false,
                'blocking_fields' => ['truth.services', 'truth.annotations', 'truth.expected_plans'],
            ],
            'sources' => [
                $this->pendingSource('sample'),
                $this->pendingSource('hard'),
            ],
        ];
        $corpus['corpus_hash'] = CanonicalJson::hash($corpus);

        return $corpus;
    }

    /** @return array<string, mixed> */
    private function pendingSource(string $itemKey): array
    {
        $source = OosEmailSourceDocument::fromContext('Order', "Morning service\nSong One", '2026-08-19');

        return [
            'item_key' => $itemKey,
            'source_document' => [
                'input_hash' => $source->inputHash(),
                'subject' => $source->subject,
                'received_date' => $source->receivedDate,
                'lines' => $source->semanticLineRecords(),
            ],
            'truth' => [
                'format' => FreezeOosSemanticEvaluationCorpus::TruthFormat,
                'version' => FreezeOosSemanticEvaluationCorpus::TruthVersion,
                'adjudication_state' => 'pending',
                'services' => null,
                'annotations' => null,
                'expected_plans' => null,
                'adjudicated_by' => null,
                'adjudicated_at' => null,
            ],
        ];
    }
}
