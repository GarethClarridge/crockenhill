<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\ChurchService\OpenLpApprovedCorpus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;

class GenerateOpenLpCorpusExpectationCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/openlp-expectation-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/raw', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/raw/*') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($this->root.'/manifest.json');
        @rmdir($this->root.'/raw');
        @rmdir($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_refuses_without_an_approved_manifest_and_a_corpus_root(): void
    {
        $this->artisan('openlp:generate-corpus-expectation')
            ->expectsOutputToContain('Both --manifest= and --path= are required.')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_derives_the_expectation_from_the_approved_manifest(): void
    {
        $this->writeCorpus();

        $output = $this->root.'/expectation.json';

        $this->artisan('openlp:generate-corpus-expectation', [
            '--manifest' => $this->root.'/manifest.json',
            '--path' => $this->root.'/raw',
            '--output' => $output,
        ])->assertExitCode(0);

        $expectation = json_decode((string) file_get_contents($output), true);

        $this->assertSame(OpenLpApprovedCorpus::Format, $expectation['format']);
        $this->assertSame('openlp', $expectation['source']);
        $this->assertCount(1, $expectation['approved_sources']);
        $this->assertSame('2016-01-03 am.osz', $expectation['approved_sources'][0]['source_key']);
        $this->assertSame('2016-01-03', $expectation['approved_sources'][0]['identity']['date']);
    }

    /**
     * A hold that names an entry the manifest does not include waives nothing,
     * so accepting it would quietly carry a stale decision forward.
     */
    #[Test]
    public function it_refuses_an_accepted_hold_the_manifest_does_not_include(): void
    {
        $this->writeCorpus();

        $holds = $this->root.'/holds.json';
        file_put_contents($holds, json_encode([['item_key' => 'openlp:absent.osz', 'reason' => 'stale']]));

        $this->artisan('openlp:generate-corpus-expectation', [
            '--manifest' => $this->root.'/manifest.json',
            '--path' => $this->root.'/raw',
            '--accepted-holds' => $holds,
        ])->expectsOutputToContain('is not an approved entry in this manifest')
            ->assertExitCode(1);

        @unlink($holds);
    }

    private function writeCorpus(): void
    {
        $archive = $this->root.'/raw/2016-01-03 AM.osz';

        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2016-01-03 AM.osz',
            osjName: '2016-01-03 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader(title: 'A Song #1', searchTitle: 'a song 1@ 1 a song'),
                ),
            ]),
        );

        copy((string) $upload->getRealPath(), $archive);

        $manifest = [
            'format' => 'crockenhill-openlp-curation',
            'version' => 3,
            'batch_key' => 'openlp-curated-test',
            'expected_counts' => ['raw' => 1, 'include' => 1, 'duplicate-of' => 0, 'exclude' => 0, 'aliases' => 0],
            'entries' => [[
                'item_key' => 'openlp:2016-01-03 AM.osz',
                'source_kind' => 'openlp',
                'relative_path' => '2016-01-03 AM.osz',
                'sha256' => hash_file('sha256', $archive),
                'byte_size' => filesize($archive),
                'disposition' => 'include',
                'logical_upload_filename' => '2016-01-03 AM.osz',
                'resolved_date' => '2016-01-03',
                'resolved_service' => 'morning',
                'alias_reason' => null,
                'parse_decision' => 'strict',
                'concatenation_decision' => 'none',
                'expected_item_count' => 1,
                'decided_by' => null,
                'decided_at' => null,
                'decision_rule_version' => 'openlp-filename-pattern-v2',
            ]],
        ];

        file_put_contents($this->root.'/manifest.json', json_encode($manifest));
    }
}
