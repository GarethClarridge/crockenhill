<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceCorpusExpectation;
use App\Services\ChurchService\OpenLpApprovedCorpus;
use App\Services\Email\OosApprovedCorpus;
use App\Services\Email\OosArchiveEvaluator;
use App\Services\Email\OosCurationEntryFactory;
use App\Support\CanonicalJson;
use App\Support\ChurchServiceSourceKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * F1. The census could previously certify a corpus in which approved entries had
 * never staged, because the only producer of its expected membership read that
 * membership back out of the staged database: a held entry was absent from both
 * sides of the comparison, and `expected_services` was a scalar an operator typed
 * from whatever the last run happened to produce.
 *
 * These tests are written against the manifest as the authority, so a held entry
 * has somewhere to be missing *from*.
 */
class ChurchServiceCorpusExpectationTest extends TestCase
{
    use RefreshDatabase;

    private const BatchHash = 'aa11bb22cc33dd44ee55ff66aa77bb88cc99dd00ee11ff22aa33bb44cc55dd66';

    /**
     * The headline case, and the one no existing check could see. The manifest
     * approves two entries; one of them held, so nothing about it reached the
     * database at all.
     */
    #[Test]
    public function an_approved_entry_that_never_staged_is_a_named_blocker(): void
    {
        $this->stage('2015-12-13-am', '2015-12-13', 'morning');

        $result = $this->certify([
            ['2015-12-13-am', '2015-12-13', 'morning'],
            ['2015-12-20-am', '2015-12-20', 'morning'],
        ]);

        $this->assertContains(ChurchServiceCorpusExpectation::APPROVED_SOURCE_UNSTAGED, $result['blockers']);
        $this->assertContains(ChurchServiceCorpusExpectation::MANIFEST_IDENTITY_UNSTAGED, $result['blockers']);
        $this->assertSame(['2015-12-20-am'], array_column($result['unstaged_sources'], 'item_key'));
        $this->assertSame('2015-12-20 morning', $result['unstaged_identities'][0]['identity']);
    }

    #[Test]
    public function a_fully_staged_corpus_certifies(): void
    {
        $this->stage('2015-12-13-am', '2015-12-13', 'morning');
        $this->stage('2015-12-13-pm', '2015-12-13', 'evening');

        $result = $this->certify([
            ['2015-12-13-am', '2015-12-13', 'morning'],
            ['2015-12-13-pm', '2015-12-13', 'evening'],
        ]);

        $this->assertTrue($result['approved']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(2, $result['expected_services']);
        $this->assertSame(2, $result['staged_identities']);
    }

    #[Test]
    public function a_staged_source_with_a_different_input_hash_is_a_named_blocker(): void
    {
        $this->stage('2015-12-13-am', '2015-12-13', 'morning');

        ChurchServiceSourceRecord::query()->sole()->update([
            'input_hash' => str_repeat('b', 64),
        ]);

        $result = $this->certify([['2015-12-13-am', '2015-12-13', 'morning']]);

        $this->assertContains(ChurchServiceCorpusExpectation::APPROVED_SOURCE_INPUT_HASH_MISMATCH, $result['blockers']);
        $this->assertSame(['2015-12-13-am'], array_column($result['input_hash_mismatches'], 'item_key'));
    }

    #[Test]
    public function a_full_source_staged_as_partial_is_a_named_blocker(): void
    {
        $this->stage('2015-12-13-am', '2015-12-13', 'morning');

        ChurchServiceSourceRecord::query()->sole()->update([
            'payload_complete' => false,
        ]);

        $result = $this->certify([['2015-12-13-am', '2015-12-13', 'morning']]);

        $this->assertContains(ChurchServiceCorpusExpectation::APPROVED_SOURCE_CONTENT_SCOPE_MISMATCH, $result['blockers']);
        $this->assertSame(['2015-12-13-am'], array_column($result['content_scope_mismatches'], 'item_key'));
    }

    /**
     * The reason a scalar count is the wrong instrument. One approved email
     * routinely carries both that Sunday's morning and evening orders, and
     * {@see OosArchiveEvaluator} imports both deliberately, so
     * a correct corpus stages more identities than the manifest names.
     */
    #[Test]
    public function an_extra_identity_an_approved_entry_explains_is_admitted(): void
    {
        $this->stage('2015-12-13-am', '2015-12-13', 'morning');
        $this->stage('2015-12-13-am', '2015-12-13', 'evening');

        $result = $this->certify([['2015-12-13-am', '2015-12-13', 'morning']]);

        $this->assertSame([], $result['blockers']);
        $this->assertSame(['2015-12-13 evening'], array_column($result['explained_beyond_manifest'], 'identity'));
        $this->assertSame([], $result['unexplained_identities']);
        $this->assertSame(1, $result['expected_services']);
        $this->assertSame(2, $result['staged_identities']);
    }

    #[Test]
    public function an_identity_no_approved_entry_explains_fails_closed(): void
    {
        $this->stage('2015-12-13-am', '2015-12-13', 'morning');
        $this->stage('1999-01-01-forgery', '1999-01-01', 'morning');

        $result = $this->certify([['2015-12-13-am', '2015-12-13', 'morning']]);

        $this->assertContains(ChurchServiceCorpusExpectation::UNEXPLAINED_IDENTITY, $result['blockers']);
        $this->assertSame(['1999-01-01 morning'], array_column($result['unexplained_identities'], 'identity'));
    }

    /**
     * `service_beyond_manifest` widens an entry's *service*, never its date — the
     * evaluator only offers plans on the manifest's resolved date. An approved
     * origin appearing on some other date is therefore not an explanation.
     */
    #[Test]
    public function an_approved_origin_on_a_different_date_does_not_explain_an_extra_identity(): void
    {
        $this->stage('2015-12-13-am', '2015-12-13', 'morning');
        $this->stage('2015-12-13-am', '2015-12-20', 'morning');

        $result = $this->certify([['2015-12-13-am', '2015-12-13', 'morning']]);

        $this->assertContains(ChurchServiceCorpusExpectation::UNEXPLAINED_IDENTITY, $result['blockers']);
        $this->assertSame(['2015-12-20 morning'], array_column($result['unexplained_identities'], 'identity'));
    }

    /**
     * One untraceable revision makes the identity unexplained however many of its
     * siblings are approved, so an off-manifest write cannot hide behind a
     * legitimate one on the same slot.
     */
    #[Test]
    public function one_unexplained_revision_taints_an_otherwise_explained_identity(): void
    {
        $this->stage('2015-12-13-am', '2015-12-13', 'morning');
        $service = $this->stage('2015-12-13-am', '2015-12-13', 'evening');
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => ChurchServiceSource::Email,
            'batch_hash' => self::BatchHash,
            'source_key' => '<not-from-any-manifest-entry@example.test>|evening:2015-12-13',
        ]);

        $result = $this->certify([['2015-12-13-am', '2015-12-13', 'morning']]);

        $this->assertContains(ChurchServiceCorpusExpectation::UNEXPLAINED_IDENTITY, $result['blockers']);
        $this->assertSame(['2015-12-13 evening'], array_column($result['unexplained_identities'], 'identity'));
    }

    #[Test]
    public function a_revision_from_another_batch_is_not_read_as_this_corpus(): void
    {
        $service = ChurchService::factory()->create(['date' => '2015-12-13', 'service' => 'morning']);
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => ChurchServiceSource::Email,
            'batch_hash' => str_repeat('9', 64),
            'source_key' => OosCurationEntryFactory::sourceKey('2015-12-13-am', 'morning', '2015-12-13'),
        ]);

        $result = $this->certify([['2015-12-13-am', '2015-12-13', 'morning']]);

        $this->assertContains(ChurchServiceCorpusExpectation::EXPECTATION_BATCH_UNSTAGED, $result['blockers']);
        $this->assertContains(ChurchServiceCorpusExpectation::APPROVED_SOURCE_UNSTAGED, $result['blockers']);
    }

    /**
     * The 2026-08-15 adjudication ended with entries that are correct and
     * intentionally held. A written acceptance turns those from a permanent
     * blocker into recorded state; nothing else does.
     */
    #[Test]
    public function an_accepted_hold_suppresses_the_blocker_and_stays_visible(): void
    {
        $this->stage('2015-12-13-am', '2015-12-13', 'morning');

        $result = $this->certify(
            [['2015-12-13-am', '2015-12-13', 'morning'], ['2015-12-20-am', '2015-12-20', 'morning']],
            acceptedHolds: [['item_key' => '2015-12-20-am', 'reason' => 'Extractor cannot resolve the split order; documented limitation.']],
        );

        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['unstaged_sources']);
        $this->assertSame([], $result['unstaged_identities']);
        $this->assertSame(['2015-12-20-am'], array_column($result['accepted_holds'], 'item_key'));
        $this->assertSame(2, $result['expected_services']);
    }

    #[Test]
    public function an_acceptance_naming_an_entry_the_manifest_does_not_include_is_rejected(): void
    {
        $this->stage('2015-12-13-am', '2015-12-13', 'morning');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an approved entry');

        $this->certify(
            [['2015-12-13-am', '2015-12-13', 'morning']],
            acceptedHolds: [['item_key' => '2011-01-01-gone', 'reason' => 'stale']],
        );
    }

    #[Test]
    public function an_acceptance_without_a_reason_is_rejected(): void
    {
        $this->stage('2015-12-13-am', '2015-12-13', 'morning');

        $this->expectException(RuntimeException::class);

        $this->certify(
            [['2015-12-13-am', '2015-12-13', 'morning']],
            acceptedHolds: [['item_key' => '2015-12-13-am', 'reason' => '  ']],
        );
    }

    #[Test]
    public function a_tampered_expectation_is_rejected(): void
    {
        $expectation = $this->expectation([['2015-12-13-am', '2015-12-13', 'morning']]);
        $expectation['approved_sources'][0]['identity']['date'] = '2015-12-20';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('format, contents or hash is invalid');

        app(ChurchServiceCorpusExpectation::class)->certify($expectation);
    }

    /**
     * A lane has exactly one approved manifest. Two artifacts naming the same lane
     * are two conflicting statements of what it should contain, and quietly taking
     * either would let a superseded manifest certify the round — so this is refused
     * rather than reconciled.
     */
    #[Test]
    public function two_expectations_for_one_lane_are_refused(): void
    {
        $this->stage('2015-12-13-am', '2015-12-13', 'morning');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Two corpus expectations describe the email lane');

        app(ChurchServiceCorpusExpectation::class)->certifyAll([
            $this->expectation([['2015-12-13-am', '2015-12-13', 'morning']]),
            $this->expectation([['2015-12-13-am', '2015-12-13', 'morning']]),
        ]);
    }

    #[Test]
    public function an_unsupplied_expectation_is_unapproved_rather_than_empty(): void
    {
        $result = app(ChurchServiceCorpusExpectation::class)->certifyAll([]);

        $this->assertFalse($result['approved']);
        $this->assertSame([], $result['lanes']);
        $this->assertSame(['expectation_unapproved'], $result['blockers']);
    }

    /**
     * The Email lane admits an extra identity when an approved entry's origin
     * explains it, because one email carries both of a Sunday's orders. An
     * OpenLP `.osz` is exactly one service, so there is nothing to widen and an
     * unnamed identity must fail closed rather than borrow Email's rule.
     */
    #[Test]
    public function an_openlp_identity_the_manifest_does_not_name_is_unexplained(): void
    {
        $approved = ChurchService::query()->firstOrCreate(
            ['date' => '2016-01-03', 'service' => 'morning'],
            ChurchService::factory()->make(['date' => '2016-01-03', 'service' => 'morning'])->getAttributes(),
        );
        $this->stageOpenLp($approved, '20160103am.osz');

        $extra = ChurchService::query()->firstOrCreate(
            ['date' => '2016-01-10', 'service' => 'evening'],
            ChurchService::factory()->make(['date' => '2016-01-10', 'service' => 'evening'])->getAttributes(),
        );
        $this->stageOpenLp($extra, '20160110pm.osz');

        $result = app(ChurchServiceCorpusExpectation::class)->certify(
            $this->openLpExpectation([['openlp:20160103am.osz', '20160103am.osz', '2016-01-03', 'morning']]),
        );

        $this->assertSame([], $result['explained_beyond_manifest']);
        $this->assertCount(1, $result['unexplained_identities']);
        $this->assertContains(
            ChurchServiceCorpusExpectation::UNEXPLAINED_IDENTITY,
            $result['blockers'],
        );
    }

    #[Test]
    public function an_openlp_corpus_that_staged_exactly_its_manifest_reconciles_clean(): void
    {
        $service = ChurchService::query()->firstOrCreate(
            ['date' => '2016-01-03', 'service' => 'morning'],
            ChurchService::factory()->make(['date' => '2016-01-03', 'service' => 'morning'])->getAttributes(),
        );
        $this->stageOpenLp($service, '20160103am.osz');

        $result = app(ChurchServiceCorpusExpectation::class)->certify(
            $this->openLpExpectation([['openlp:20160103am.osz', '20160103am.osz', '2016-01-03', 'morning']]),
        );

        $this->assertSame([], $result['blockers']);
        $this->assertSame(1, $result['expected_services']);
        $this->assertSame(1, $result['staged_identities']);
    }

    private function stageOpenLp(ChurchService $service, string $logicalFilename): void
    {
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => ChurchServiceSource::OpenLp,
            'batch_hash' => self::BatchHash,
            'source_key' => $logicalFilename,
            'input_hash' => hash('sha256', "openlp:{$logicalFilename}"),
        ]);
    }

    /**
     * @param  list<array{0:string,1:string,2:string,3:string}>  $entries
     * @return array<string, mixed>
     */
    private function openLpExpectation(array $entries): array
    {
        $sources = [];

        foreach ($entries as [$itemKey, $logicalFilename, $date, $service]) {
            $sources[] = [
                'item_key' => $itemKey,
                'origin' => $itemKey,
                'source_key' => ChurchServiceSourceKey::canonical($logicalFilename),
                'input_hash' => hash('sha256', $itemKey),
                'identity' => ['date' => $date, 'service' => $service],
                'content_scope' => 'full',
            ];
        }

        $expectation = [
            'format' => OpenLpApprovedCorpus::Format,
            'version' => OpenLpApprovedCorpus::Version,
            'source' => OpenLpApprovedCorpus::Source,
            'batch_key' => 'openlp-curated-test',
            'batch_hash' => self::BatchHash,
            'manifest_hash' => str_repeat('f', 64),
            'approved_sources' => $sources,
        ];
        $expectation['expectation_hash'] = CanonicalJson::hash($expectation);

        return $expectation;
    }

    private function stage(string $itemKey, string $date, string $service): ChurchService
    {
        $churchService = ChurchService::query()->firstOrCreate(
            ['date' => $date, 'service' => $service],
            ChurchService::factory()->make(['date' => $date, 'service' => $service])->getAttributes(),
        );

        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $churchService->id,
            'source' => ChurchServiceSource::Email,
            'batch_hash' => self::BatchHash,
            'source_key' => OosCurationEntryFactory::sourceKey($itemKey, $service, $date),
            'input_hash' => hash('sha256', $itemKey),
        ]);

        return $churchService;
    }

    /**
     * @param  list<array{0:string,1:string,2:string}>  $entries
     * @param  list<array{item_key:string,reason:string}>  $acceptedHolds
     * @return array<string, mixed>
     */
    private function certify(array $entries, array $acceptedHolds = []): array
    {
        return app(ChurchServiceCorpusExpectation::class)
            ->certify($this->expectation($entries, $acceptedHolds));
    }

    /**
     * @param  list<array{0:string,1:string,2:string}>  $entries
     * @param  list<array{item_key:string,reason:string}>  $acceptedHolds
     * @return array<string, mixed>
     */
    private function expectation(array $entries, array $acceptedHolds = []): array
    {
        $sources = [];

        foreach ($entries as [$itemKey, $date, $service]) {
            $sources[] = [
                'item_key' => $itemKey,
                'origin' => OosCurationEntryFactory::messageId($itemKey),
                'source_key' => OosCurationEntryFactory::sourceKey($itemKey, $service, $date),
                'input_hash' => hash('sha256', $itemKey),
                'identity' => ['date' => $date, 'service' => $service],
                'content_scope' => 'full',
            ];
        }

        $expectation = [
            'format' => OosApprovedCorpus::Format,
            'version' => OosApprovedCorpus::Version,
            'source' => OosApprovedCorpus::Source,
            'batch_key' => 'oos-curated-test',
            'batch_hash' => self::BatchHash,
            'manifest_hash' => str_repeat('f', 64),
            'approved_sources' => $sources,
            ...($acceptedHolds === [] ? [] : ['accepted_holds' => $acceptedHolds]),
        ];
        $expectation['expectation_hash'] = CanonicalJson::hash($expectation);

        return $expectation;
    }
}
