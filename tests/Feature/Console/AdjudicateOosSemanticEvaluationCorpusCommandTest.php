<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\OosEmailSourceDocument;
use App\Services\Email\FreezeOosSemanticEvaluationCorpus;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdjudicateOosSemanticEvaluationCorpusCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/semantic-adjudication-command-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_writes_a_create_once_private_adjudicated_corpus(): void
    {
        $corpusPath = "{$this->root}/prefilled.json";
        $decisionsPath = "{$this->root}/decisions.json";
        $outputPath = "{$this->root}/adjudicated.json";
        File::put($corpusPath, json_encode($this->corpus(), JSON_THROW_ON_ERROR));
        File::put($decisionsPath, json_encode(['sample' => $this->decision()], JSON_THROW_ON_ERROR));

        $this->artisan('oos:adjudicate-semantic-corpus', [
            '--corpus' => $corpusPath,
            '--decisions' => $decisionsPath,
            '--output' => $outputPath,
        ])->expectsOutputToContain('scoreable: true')->assertSuccessful();

        $this->assertFileExists($outputPath);
        $this->assertSame(0600, fileperms($outputPath) & 0777);
        $artifact = json_decode((string) file_get_contents($outputPath), true);
        $this->assertSame('adjudicated', $artifact['sources'][0]['truth']['adjudication_state']);

        $this->artisan('oos:adjudicate-semantic-corpus', [
            '--corpus' => $corpusPath,
            '--decisions' => $decisionsPath,
            '--output' => $outputPath,
        ])->expectsOutputToContain('Refusing to overwrite')->assertFailed();
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
        $source = OosEmailSourceDocument::fromContext('Order', "Morning service\nSong One", '2026-08-19');
        $corpus = [
            'format' => FreezeOosSemanticEvaluationCorpus::Format,
            'version' => FreezeOosSemanticEvaluationCorpus::Version,
            'completeness' => [
                'source_count' => 1,
                'fully_adjudicated_sources' => 0,
                'pending_sources' => 1,
                'scoreable' => false,
                'blocking_fields' => ['truth.services', 'truth.annotations', 'truth.expected_plans'],
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
                    'format' => FreezeOosSemanticEvaluationCorpus::TruthFormat,
                    'version' => FreezeOosSemanticEvaluationCorpus::TruthVersion,
                    'adjudication_state' => 'pending',
                    'services' => null,
                    'annotations' => null,
                    'expected_plans' => null,
                    'adjudicated_by' => null,
                    'adjudicated_at' => null,
                ],
            ]],
        ];
        $corpus['corpus_hash'] = CanonicalJson::hash($corpus);

        return $corpus;
    }
}
