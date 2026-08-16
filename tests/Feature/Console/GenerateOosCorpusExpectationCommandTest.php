<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\ChurchService\ChurchServiceCorpusExpectation;
use App\Services\Email\OosApprovedCorpus;
use App\Services\Email\OosCurationEntryFactory;
use App\Services\Email\OosCurationManifest;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The producer F1 was missing. `expected_services` was a scalar an operator typed
 * and the membership artifact was generated from the staged database, so the only
 * two statements of what the corpus should contain were both downstream of what it
 * did contain.
 */
class GenerateOosCorpusExpectationCommandTest extends TestCase
{
    private string $root;

    private string $verbatimRoot;

    private string $formattedRoot;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/oos-expectation-'.bin2hex(random_bytes(6));
        $this->verbatimRoot = $this->root.'/oos-verbatim';
        $this->formattedRoot = $this->root.'/oos';
        $this->manifestPath = $this->root.'/manifest.json';

        File::makeDirectory($this->verbatimRoot, 0755, true);
        File::makeDirectory($this->formattedRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_writes_an_expectation_the_reconciler_accepts(): void
    {
        $this->writeManifest([
            $this->entry('2015-12-13-am', '2015-12-13', 'morning'),
            $this->entry('2015-12-13-pm', '2015-12-13', 'evening'),
        ]);
        $output = $this->root.'/expectation.json';

        $this->artisan('oos:generate-corpus-expectation', [
            '--manifest' => $this->manifestPath,
            '--verbatim' => $this->verbatimRoot,
            '--formatted' => $this->formattedRoot,
            '--output' => $output,
        ])->assertSuccessful();

        $expectation = app(ChurchServiceCorpusExpectation::class)->fromFile($output);

        $this->assertSame(OosApprovedCorpus::Format, $expectation['format']);
        $this->assertCount(2, $expectation['approved_sources']);
        $this->assertSame(
            OosCurationEntryFactory::sourceKey('2015-12-13-am', 'morning', '2015-12-13'),
            $expectation['approved_sources'][0]['source_key'],
        );
    }

    /**
     * The expectation must be pinned to the manifest that produced it, or an
     * expectation from one curation batch could certify a corpus staged from
     * another. The importer records the plan hash as every revision's `batch_hash`,
     * so that is the value carried.
     */
    #[Test]
    public function the_expectation_carries_the_plan_hash_the_importer_stamps_on_every_revision(): void
    {
        $this->writeManifest([$this->entry('2015-12-13-am', '2015-12-13', 'morning')]);
        $output = $this->root.'/expectation.json';

        $this->artisan('oos:generate-corpus-expectation', [
            '--manifest' => $this->manifestPath,
            '--verbatim' => $this->verbatimRoot,
            '--formatted' => $this->formattedRoot,
            '--output' => $output,
        ])->assertSuccessful();

        $expectation = json_decode((string) file_get_contents($output), true);
        $planHash = app(OosCurationManifest::class)
            ->plan($this->verbatimRoot, $this->formattedRoot, $this->manifestPath)
            ->planHash;

        $this->assertSame($planHash, $expectation['batch_hash']);
    }

    #[Test]
    public function accepted_holds_are_folded_in_before_the_expectation_is_hashed(): void
    {
        $this->writeManifest([$this->entry('2015-12-13-am', '2015-12-13', 'morning')]);
        $holds = $this->root.'/holds.json';
        $output = $this->root.'/expectation.json';
        File::put($holds, (string) json_encode([
            ['item_key' => '2015-12-13-am', 'reason' => 'Documented extractor limitation; confirmed correct 2026-08-15.'],
        ]));

        $this->artisan('oos:generate-corpus-expectation', [
            '--manifest' => $this->manifestPath,
            '--verbatim' => $this->verbatimRoot,
            '--formatted' => $this->formattedRoot,
            '--accepted-holds' => $holds,
            '--output' => $output,
        ])->assertSuccessful();

        $expectation = app(ChurchServiceCorpusExpectation::class)->fromFile($output);

        $this->assertCount(1, $expectation['accepted_holds']);
        $this->assertSame('2015-12-13-am', $expectation['accepted_holds'][0]['item_key']);
    }

    #[Test]
    public function an_accepted_hold_for_an_entry_the_manifest_does_not_include_fails(): void
    {
        $this->writeManifest([$this->entry('2015-12-13-am', '2015-12-13', 'morning')]);
        $holds = $this->root.'/holds.json';
        File::put($holds, (string) json_encode([['item_key' => '1999-01-01-gone', 'reason' => 'stale']]));

        $this->artisan('oos:generate-corpus-expectation', [
            '--manifest' => $this->manifestPath,
            '--verbatim' => $this->verbatimRoot,
            '--formatted' => $this->formattedRoot,
            '--accepted-holds' => $holds,
            '--output' => $this->root.'/expectation.json',
        ])->assertFailed();

        $this->assertFileDoesNotExist($this->root.'/expectation.json');
    }

    #[Test]
    public function an_accepted_hold_without_a_reason_fails(): void
    {
        $this->writeManifest([$this->entry('2015-12-13-am', '2015-12-13', 'morning')]);
        $holds = $this->root.'/holds.json';
        File::put($holds, (string) json_encode([['item_key' => '2015-12-13-am', 'reason' => '']]));

        $this->artisan('oos:generate-corpus-expectation', [
            '--manifest' => $this->manifestPath,
            '--verbatim' => $this->verbatimRoot,
            '--formatted' => $this->formattedRoot,
            '--accepted-holds' => $holds,
            '--output' => $this->root.'/expectation.json',
        ])->assertFailed();
    }

    #[Test]
    public function it_refuses_to_run_without_a_manifest(): void
    {
        $this->artisan('oos:generate-corpus-expectation')->assertFailed();
    }

    /** @return array<string, mixed> */
    private function entry(string $itemKey, string $date, string $service): array
    {
        $verbatim = "---\nsource_date: {$date}\n---\n\nraw {$itemKey}";
        $formatted = "formatted {$itemKey}";

        File::put("{$this->verbatimRoot}/{$itemKey}.md", $verbatim);
        File::put("{$this->formattedRoot}/{$itemKey}.md", $formatted);

        return [
            'item_key' => $itemKey,
            'source_kind' => 'email',
            'verbatim_relative_path' => "{$itemKey}.md",
            'verbatim_sha256' => hash('sha256', $verbatim),
            'verbatim_byte_size' => strlen($verbatim),
            'formatted_relative_path' => "{$itemKey}.md",
            'formatted_sha256' => hash('sha256', $formatted),
            'formatted_byte_size' => strlen($formatted),
            'disposition' => 'include',
            'payload' => 'formatted',
            'resolved_date' => $date,
            'resolved_service' => $service,
            'date_decision' => 'explicit',
            'content_scope' => 'full',
            'parse_decision' => 'strict',
            'decided_by' => 'maintainer@example.test',
            'decided_at' => '2026-08-16T10:00:00+00:00',
        ];
    }

    /** @param list<array<string, mixed>> $entries */
    private function writeManifest(array $entries): void
    {
        File::put($this->manifestPath, (string) json_encode([
            'format' => 'crockenhill-oos-curation',
            'version' => 1,
            'batch_key' => 'oos-curated-test',
            'entries' => $entries,
        ], JSON_THROW_ON_ERROR));
    }
}
