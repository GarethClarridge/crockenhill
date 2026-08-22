<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosCurationPlan;
use App\Services\ChurchService\SourceAdapters\EmailSourceAdapter;
use App\Services\Email\OosApprovedCorpus;
use App\Services\Email\OosCurationEntryFactory;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The expectation is only worth anything if it predicts exactly what the importer
 * writes. Every assertion here is therefore about agreement with the import path
 * rather than about the artifact's shape in isolation.
 */
class OosApprovedCorpusTest extends TestCase
{
    #[Test]
    public function it_derives_the_source_key_the_importer_will_write(): void
    {
        $expectation = $this->expectation([$this->include('2015-12-13-am', '2015-12-13', 'morning')]);
        $approved = $expectation['approved_sources'][0];

        $this->assertSame(
            OosCurationEntryFactory::sourceKey('2015-12-13-am', 'morning', '2015-12-13'),
            $approved['source_key'],
        );
        $this->assertSame(OosCurationEntryFactory::messageId('2015-12-13-am'), $approved['origin']);
        $this->assertSame(
            $approved['origin'],
            OosCurationEntryFactory::originOf($approved['source_key']),
        );
    }

    /**
     * {@see EmailSourceAdapter::adapt()} records the curation plan hash as the
     * revision's `batch_hash` and the entry's approved digest as its `input_hash`.
     * Both are restated here rather than recomputed, so an expectation cannot
     * certify a corpus staged from a different manifest.
     */
    #[Test]
    public function it_binds_to_the_plan_hash_and_the_approved_payload_digest(): void
    {
        $expectation = $this->expectation(
            [$this->include('2015-12-13-am', '2015-12-13', 'morning', sha256: str_repeat('a', 64))],
            planHash: str_repeat('b', 64),
        );

        $this->assertSame(str_repeat('b', 64), $expectation['batch_hash']);
        $this->assertSame(str_repeat('a', 64), $expectation['approved_sources'][0]['input_hash']);
    }

    #[Test]
    public function it_records_every_included_entry_including_partials_and_superseded_ones(): void
    {
        $expectation = $this->expectation([
            $this->include('2016-04-24-am', '2016-04-24', 'morning'),
            $this->include('2016-04-24-hymns', '2016-04-24', 'morning', contentScope: 'partial'),
            $this->include('2020-03-15-hymns-revised', '2020-03-15', 'morning'),
        ]);

        $this->assertCount(3, $expectation['approved_sources']);
        $this->assertSame(
            ['full', 'partial'],
            array_values(array_unique(array_column(
                array_filter(
                    $expectation['approved_sources'],
                    static fn (array $source): bool => $source['identity']['date'] === '2016-04-24',
                ),
                'content_scope',
            ))),
        );
    }

    /**
     * Two entries on one date and service are the normal corrected-order shape, so
     * the source count and the identity count deliberately differ.
     */
    #[Test]
    public function several_approved_entries_may_resolve_to_one_identity(): void
    {
        $expectation = $this->expectation([
            $this->include('2016-04-24-am', '2016-04-24', 'morning'),
            $this->include('2016-04-24-hymns', '2016-04-24', 'morning', contentScope: 'partial'),
        ]);

        $identities = array_unique(array_map(
            static fn (array $source): string => $source['identity']['date'].' '.$source['identity']['service'],
            $expectation['approved_sources'],
        ));

        $this->assertCount(2, $expectation['approved_sources']);
        $this->assertCount(1, $identities);
    }

    #[Test]
    public function the_expectation_hash_covers_the_approved_sources(): void
    {
        $expectation = $this->expectation([$this->include('2015-12-13-am', '2015-12-13', 'morning')]);
        $hash = $expectation['expectation_hash'];

        unset($expectation['expectation_hash']);
        $this->assertSame($hash, CanonicalJson::hash($expectation));

        $expectation['approved_sources'][0]['identity']['service'] = 'evening';
        $this->assertNotSame($hash, CanonicalJson::hash($expectation));
    }

    #[Test]
    public function the_ordering_of_manifest_entries_does_not_change_the_hash(): void
    {
        $first = $this->include('2015-12-13-am', '2015-12-13', 'morning');
        $second = $this->include('2015-12-13-pm', '2015-12-13', 'evening');

        $this->assertSame(
            $this->expectation([$first, $second])['expectation_hash'],
            $this->expectation([$second, $first])['expectation_hash'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $includes
     * @return array<string, mixed>
     */
    private function expectation(array $includes, string $planHash = 'plan-hash'): array
    {
        return app(OosApprovedCorpus::class)->expectation(new OosCurationPlan(
            manifestHash: 'manifest-hash',
            planHash: $planHash,
            includes: $includes,
            counts: [],
            batchKey: 'oos-curated-test',
        ));
    }

    /** @return array<string, mixed> */
    private function include(
        string $itemKey,
        string $date,
        string $service,
        string $contentScope = 'full',
        string $sha256 = 'source-hash',
    ): array {
        return [
            'item_key' => $itemKey,
            'source_kind' => 'email',
            'relative_path' => "{$itemKey}.md",
            'sha256' => $sha256,
            'byte_size' => 42,
            'payload' => 'formatted',
            'verbatim_relative_path' => "{$itemKey}.md",
            'formatted_relative_path' => "{$itemKey}.md",
            'source_date' => $date,
            'resolved_date' => $date,
            'resolved_service' => $service,
            'additional_services' => [],
            'additional_service_labels' => [],
            'curation_note' => null,
            'service_label' => null,
            'title_override' => null,
            'date_decision' => 'explicit',
            'date_decision_reason' => null,
            'content_scope' => $contentScope,
            'partial_scope_reason' => $contentScope === 'partial' ? 'hymn list only' : null,
            'supersedes' => null,
            'parse_decision' => 'strict',
            'expected_item_count' => null,
            'decided_by' => 'maintainer@example.test',
            'decided_at' => '2026-08-16T10:00:00+00:00',
            'decision_rule_version' => null,
        ];
    }
}
