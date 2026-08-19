<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticParserOutcome;
use App\Services\Email\FreezeOosSemanticEvaluationCorpus;
use App\Services\Email\OosParserSurfaceFingerprint;
use App\Services\Email\OosSemanticAnnotationPrompt;
use App\Services\Email\OosSemanticCandidateEvidenceRunner;
use App\Services\Email\OosSemanticEvaluationCorpusGate;
use App\Services\Email\OosSemanticParserCandidate;
use App\Support\CanonicalJson;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OosSemanticCandidateEvidenceRunnerTest extends TestCase
{
    #[Test]
    public function it_refuses_incomplete_truth_before_calling_the_candidate(): void
    {
        $candidate = Mockery::mock(OosSemanticParserCandidate::class);
        $candidate->shouldNotReceive('parse');
        $runner = $this->runner($candidate);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing paid model calls');
        $runner->run($this->corpus(adjudicated: false), $this->priceSnapshot());
    }

    #[Test]
    public function it_binds_every_call_and_usage_record_to_one_returned_model(): void
    {
        config()->set('service-tracking.email_parsing.semantic.model', 'gpt-5.6-terra');
        config()->set('service-tracking.email_parsing.semantic.reasoning_effort', 'low');
        config()->set('openai.service_tier', 'default');
        $candidate = Mockery::mock(OosSemanticParserCandidate::class);
        $candidate->shouldReceive('parse')->once()->andReturn(new OosSemanticParserOutcome(
            new OosEmailItemExtractionResult([], 0.0, services: [], serviceCount: 0, provenanceComplete: true),
            [[
                'initial_annotations' => ['telemetry' => [
                    'returned_model' => 'gpt-5.6-terra-2026-08-01',
                    'latency_ms' => 125,
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120],
                ]],
                'repair_telemetry' => [
                    'returned_model' => 'gpt-5.6-terra-2026-08-01',
                    'latency_ms' => 75,
                    'usage' => ['input_tokens' => 40, 'output_tokens' => 10, 'total_tokens' => 50],
                ],
            ]],
            ['targeted_repair_required' => true],
        ));

        $artifact = $this->runner($candidate)->run($this->corpus(adjudicated: true), $this->priceSnapshot());

        $this->assertSame(OosSemanticCandidateEvidenceRunner::Format, $artifact['format']);
        $this->assertSame('gpt-5.6-terra-2026-08-01', $artifact['candidate']['returned_model']);
        $this->assertSame('low', $artifact['candidate']['reasoning_effort']);
        $this->assertSame(2, $artifact['usage']['calls']);
        $this->assertSame(170, $artifact['usage']['total_tokens']);
        $this->assertSame(200, $artifact['usage']['latency_ms']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact['evidence_hash']);
    }

    private function runner(OosSemanticParserCandidate $candidate): OosSemanticCandidateEvidenceRunner
    {
        return new OosSemanticCandidateEvidenceRunner(
            new OosSemanticEvaluationCorpusGate,
            $candidate,
            new OosParserSurfaceFingerprint,
            new OosSemanticAnnotationPrompt,
        );
    }

    /** @return array<string, mixed> */
    private function corpus(bool $adjudicated): array
    {
        $source = OosEmailSourceDocument::fromContext('Order', "Morning\nSong", '2026-08-19');
        $corpus = [
            'format' => FreezeOosSemanticEvaluationCorpus::Format,
            'version' => FreezeOosSemanticEvaluationCorpus::Version,
            'completeness' => [
                'scoreable' => $adjudicated,
                'fully_adjudicated_sources' => $adjudicated ? 1 : 0,
                'pending_sources' => $adjudicated ? 0 : 1,
            ],
            'sources' => [[
                'item_key' => 'sample',
                'source_document' => [
                    'input_hash' => $source->inputHash(),
                    'subject' => $source->subject,
                    'received_date' => $source->receivedDate,
                    'lines' => $source->semanticLineRecords(),
                ],
                'truth' => [
                    'adjudication_state' => $adjudicated ? 'adjudicated' : 'pending',
                    'services' => $adjudicated ? [] : null,
                    'annotations' => $adjudicated ? [] : null,
                    'expected_plans' => $adjudicated ? [] : null,
                    'adjudicated_by' => $adjudicated ? 'maintainer@example.test' : null,
                    'adjudicated_at' => $adjudicated ? '2026-08-19T12:00:00+01:00' : null,
                ],
            ]],
        ];
        $corpus['corpus_hash'] = CanonicalJson::hash($corpus);

        return $corpus;
    }

    /** @return array<string, mixed> */
    private function priceSnapshot(): array
    {
        return [
            'taken_at' => '2026-08-19',
            'billing_mode' => 'standard-short-context',
            'models' => [
                'gpt-5.6-terra' => ['input' => 2.0, 'output' => 12.0],
            ],
        ];
    }
}
