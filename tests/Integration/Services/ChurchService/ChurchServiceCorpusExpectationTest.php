<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use App\Services\ChurchService\ChurchServiceCorpusExpectation;
use App\Services\Email\OosApprovedCorpus;
use App\Services\Email\OosArchiveEvaluator;
use App\Services\Email\OosCurationEntryFactory;
use App\Support\CanonicalJson;
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

    #[Test]
    public function an_unsupplied_expectation_is_unapproved_rather_than_empty(): void
    {
        $result = app(ChurchServiceCorpusExpectation::class)->certify(null);

        $this->assertFalse($result['approved']);
        $this->assertSame(['expectation_unapproved'], $result['blockers']);
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
