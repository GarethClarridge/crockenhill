<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosEmailSourceDocument;
use App\Services\Email\FreezeOosSemanticEvaluationCorpus;
use App\Services\Email\OosSemanticEvaluationCorpusGate;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OosSemanticEvaluationCorpusGateTest extends TestCase
{
    #[Test]
    public function it_refuses_pending_truth_before_a_paid_runner_can_start(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing paid model calls');

        (new OosSemanticEvaluationCorpusGate)->assertScoreable($this->corpus(adjudicated: false));
    }

    #[Test]
    public function it_accepts_only_hash_bound_fully_adjudicated_truth(): void
    {
        $gate = new OosSemanticEvaluationCorpusGate;
        $corpus = $this->corpus(adjudicated: true);

        $gate->assertScoreable($corpus);
        $corpus['sources'][0]['source_document']['lines'][0]['exact_text'] = 'tampered';
        unset($corpus['corpus_hash']);
        $corpus['corpus_hash'] = CanonicalJson::hash($corpus);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match its source hash');
        $gate->assertScoreable($corpus);
    }

    /** @return array<string, mixed> */
    private function corpus(bool $adjudicated): array
    {
        $source = OosEmailSourceDocument::fromContext('Order', "Morning\nSong", '2026-08-19');
        $truth = $adjudicated ? [
            'adjudication_state' => 'adjudicated',
            'services' => [['group_id' => 'morning']],
            'annotations' => ['L001' => ['role' => 'service_boundary']],
            'expected_plans' => [['service' => 'morning']],
            'adjudicated_by' => 'maintainer@example.test',
            'adjudicated_at' => '2026-08-19T12:00:00+01:00',
        ] : [
            'adjudication_state' => 'pending',
            'services' => null,
            'annotations' => null,
            'expected_plans' => null,
            'adjudicated_by' => null,
            'adjudicated_at' => null,
        ];
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
                'truth' => $truth,
            ]],
        ];
        $corpus['corpus_hash'] = CanonicalJson::hash($corpus);

        return $corpus;
    }
}
