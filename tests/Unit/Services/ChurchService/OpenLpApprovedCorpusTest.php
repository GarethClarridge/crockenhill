<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Console\Commands\ImportOpenLpDirectoryCommand;
use App\Data\ChurchServiceSourceRevision;
use App\Data\OpenLpCurationPlan;
use App\Enums\ChurchServiceSource;
use App\Services\ChurchService\ChurchServiceCorpusExpectation;
use App\Services\ChurchService\OpenLpApprovedCorpus;
use App\Services\ChurchService\SourceAdapters\OpenLpSourceAdapter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The expectation is only worth anything if it predicts exactly what the importer
 * writes, so every assertion here is about agreement with the import path rather
 * than about the artifact's shape in isolation.
 */
class OpenLpApprovedCorpusTest extends TestCase
{
    /**
     * {@see ImportOpenLpDirectoryCommand} passes `$plan->manifestHash` as the
     * batch hash, where the Email importer passes the curation *plan* hash. The
     * expectation has to bind to whatever the importer actually wrote, so this
     * guards the asymmetry against being "tidied" into agreement with Email.
     */
    #[Test]
    public function it_binds_to_the_manifest_hash_because_that_is_what_the_importer_writes(): void
    {
        $expectation = $this->expectation(
            [$this->include('openlp:2016-01-03 AM.osz', '2016-01-03 AM.osz', '2016-01-03', 'morning')],
            manifestHash: str_repeat('a', 64),
            planHash: str_repeat('b', 64),
        );

        $this->assertSame(str_repeat('a', 64), $expectation['batch_hash']);
        $this->assertNotSame($expectation['batch_hash'], str_repeat('b', 64));
    }

    /**
     * {@see OpenLpSourceAdapter::adapt()} records the uploaded file's original
     * name, and the command builds that upload from `logical_upload_filename`,
     * so an aliased archive stages under its alias rather than its on-disk name.
     */
    #[Test]
    public function it_derives_the_source_key_from_the_logical_upload_filename(): void
    {
        $expectation = $this->expectation([
            $this->include(
                'openlp:AlternativeCarolService2025.osz',
                '2025-12-28 PM.osz',
                '2025-12-28',
                'evening',
            ),
        ]);

        $this->assertSame('2025-12-28 pm.osz', $expectation['approved_sources'][0]['source_key']);
    }

    /**
     * The key is compared against what is stored, and
     * {@see ChurchServiceSourceRevision} canonicalises before writing. Asserted
     * against the revision object rather than a literal, so the expectation
     * cannot drift from the importer if the canonical form ever changes.
     *
     * Emitting the raw filename passed a literal-based test and still failed on
     * the real corpus: 515 of 614 approved sources reported unstaged while every
     * identity had in fact staged.
     */
    #[Test]
    public function it_canonicalises_the_source_key_exactly_as_the_importer_does(): void
    {
        $logicalFilename = '2025-12-28 PM.osz';

        $revision = new ChurchServiceSourceRevision(
            source: ChurchServiceSource::OpenLp,
            sourceKey: $logicalFilename,
            inputHash: str_repeat('d', 64),
            assertions: [],
            processingFingerprint: ['format' => 'openlp-osz', 'version' => 1],
        );

        $expectation = $this->expectation([
            $this->include('openlp:x.osz', $logicalFilename, '2025-12-28', 'evening'),
        ]);

        $this->assertSame($revision->sourceKey, $expectation['approved_sources'][0]['source_key']);
    }

    #[Test]
    public function it_restates_the_approved_payload_digest_as_the_input_hash(): void
    {
        $expectation = $this->expectation([
            $this->include('openlp:a.osz', 'a.osz', '2016-01-03', 'morning', sha256: str_repeat('c', 64)),
        ]);

        $this->assertSame(str_repeat('c', 64), $expectation['approved_sources'][0]['input_hash']);
    }

    /**
     * An `.osz` is exactly one service, so unlike Email there is no second order
     * for an approved entry to explain and nothing to widen.
     */
    #[Test]
    public function it_declares_full_content_scope_and_a_self_origin(): void
    {
        $approved = $this->expectation([
            $this->include('openlp:a.osz', 'a.osz', '2016-01-03', 'morning'),
        ])['approved_sources'][0];

        $this->assertSame('full', $approved['content_scope']);
        $this->assertSame('openlp:a.osz', $approved['origin']);
    }

    #[Test]
    public function it_emits_the_shared_artifact_contract_the_reconciler_validates(): void
    {
        $expectation = $this->expectation([
            $this->include('openlp:a.osz', 'a.osz', '2016-01-03', 'morning'),
        ]);

        $this->assertSame(ChurchServiceCorpusExpectation::Format, $expectation['format']);
        $this->assertSame(ChurchServiceCorpusExpectation::Version, $expectation['version']);
        $this->assertSame('openlp', $expectation['source']);
    }

    /** The artifact must not depend on the order entries happen to arrive in. */
    #[Test]
    public function it_hashes_independently_of_include_order(): void
    {
        $first = $this->include('openlp:a.osz', 'a.osz', '2016-01-03', 'morning');
        $second = $this->include('openlp:b.osz', 'b.osz', '2016-01-03', 'evening');

        $this->assertSame(
            $this->expectation([$first, $second])['expectation_hash'],
            $this->expectation([$second, $first])['expectation_hash'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $includes
     * @return array<string, mixed>
     */
    private function expectation(
        array $includes,
        string $manifestHash = 'manifest-hash',
        string $planHash = 'plan-hash',
    ): array {
        return app(OpenLpApprovedCorpus::class)->expectation(new OpenLpCurationPlan(
            manifestHash: $manifestHash,
            planHash: $planHash,
            includes: $includes,
            counts: [],
            batchKey: 'openlp-curated-test',
        ));
    }

    /** @return array<string, mixed> */
    private function include(
        string $itemKey,
        string $logicalFilename,
        string $date,
        string $service,
        string $sha256 = 'source-hash',
    ): array {
        return [
            'item_key' => $itemKey,
            'source_kind' => 'openlp',
            'relative_path' => $logicalFilename,
            'sha256' => $sha256,
            'byte_size' => 1024,
            'logical_upload_filename' => $logicalFilename,
            'resolved_date' => $date,
            'resolved_service' => $service,
            'alias_reason' => null,
            'parse_decision' => 'strict',
            'concatenation_decision' => 'none',
            'expected_item_count' => 3,
            'decided_by' => null,
            'decided_at' => null,
            'decision_rule_version' => 'openlp-filename-pattern-v2',
        ];
    }
}
