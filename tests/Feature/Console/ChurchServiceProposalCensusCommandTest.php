<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceProposalClassReview;
use App\Models\ChurchServiceSourceRecord;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Services\ChurchService\ChurchServiceProposalCensus;
use App\Services\Email\OosApprovedCorpus;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceProposalCensusCommandTest extends TestCase
{
    use RefreshDatabase;

    private const EmailBatchHash = 'aa11bb22cc33dd44ee55ff66aa77bb88cc99dd00ee11ff22aa33bb44cc55dd66';

    private const OpenLpBatchHash = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @var list<string> */
    private array $artifacts = [];

    protected function tearDown(): void
    {
        foreach ($this->artifacts as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_reports_an_empty_census(): void
    {
        $this->artisan('services:proposal-census')
            ->expectsOutputToContain('The census is empty.')
            ->assertSuccessful();
    }

    #[Test]
    public function the_gate_option_fails_until_every_class_is_accounted_for(): void
    {
        $proposal = $this->proposal();
        $classKey = app(ChurchServiceProposalCensus::class)->classKey($proposal);

        $this->artisan('services:proposal-census --gate')->assertFailed();

        ChurchServiceProposalClassReview::query()->create([
            'class_key' => $classKey,
            'status' => ChurchServiceProposalClassReview::IRREDUCIBLE,
            'reason' => 'The sources genuinely disagree about order.',
            'seconds_per_decision' => 30,
            'marked_by_user_id' => User::factory()->create()->id,
        ]);

        $this->artisan('services:proposal-census --gate')
            ->expectsOutputToContain('No hash-verified, item-level corpus membership is supplied')
            ->assertFailed();
    }

    /**
     * The empty census is the dangerous one: it looks like a converged corpus and is
     * produced just as readily by a corpus nothing has been staged for.
     */
    #[Test]
    public function the_gate_option_fails_on_an_empty_census_over_an_unstaged_corpus(): void
    {
        $this->artisan('services:proposal-census --gate')
            ->expectsOutputToContain('The census is empty.')
            ->expectsOutputToContain('No approved corpus size is recorded')
            ->assertFailed();
    }

    #[Test]
    public function the_gate_option_fails_without_a_hash_verified_item_level_membership(): void
    {
        $service = ChurchService::factory()->create([
            'projection_policy_version' => ChurchServiceProjector::PROJECTION_POLICY_VERSION,
        ]);
        ChurchServiceSourceRecord::factory()->create(['church_service_id' => $service->id]);
        config()->set('church.historic_corpus.census_source_kinds', 'email');

        $this->artisan('services:proposal-census --gate --expected-services=1')
            ->expectsOutputToContain('The census is empty.')
            ->expectsOutputToContain('1 service(s) staged, 1 projected')
            ->expectsOutputToContain('No hash-verified, item-level corpus membership is supplied')
            ->assertFailed();
    }

    /**
     * A census declaring two source kinds is certified from two expectation
     * artifacts, because each is a hash-locked derivation of exactly one approved
     * manifest and there is one manifest per lane. This is the option arity that
     * made an `email,openlp` run assemblable at all; before it, the two lanes could
     * only ever be certified in separate runs.
     */
    #[Test]
    public function the_expectation_option_takes_one_artifact_per_declared_lane(): void
    {
        $service = ChurchService::factory()->create([
            'projection_policy_version' => ChurchServiceProjector::PROJECTION_POLICY_VERSION,
        ]);
        $email = ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => ChurchServiceSource::Email,
            'batch_hash' => self::EmailBatchHash,
        ]);
        $openLp = ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => ChurchServiceSource::OpenLp,
            'batch_hash' => self::OpenLpBatchHash,
        ]);
        config()->set('church.historic_corpus.census_source_kinds', 'email,openlp');

        $this->artisan('services:proposal-census', [
            '--expectation' => [$this->expectationFile($email), $this->expectationFile($openLp)],
        ])
            ->expectsOutputToContain('2 lane(s) covering email, openlp')
            ->expectsOutputToContain('email: batch email-curated-test')
            ->expectsOutputToContain('openlp: batch openlp-curated-test')
            ->assertSuccessful();
    }

    /**
     * Supplying only the Email artifact leaves the F1 check silent about OpenLP, so
     * the gate names the uncovered lane instead of reading one lane's clean
     * reconciliation as covering both.
     */
    #[Test]
    public function the_gate_option_fails_when_a_declared_lane_has_no_expectation(): void
    {
        $service = ChurchService::factory()->create([
            'projection_policy_version' => ChurchServiceProjector::PROJECTION_POLICY_VERSION,
        ]);
        $email = ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => ChurchServiceSource::Email,
            'batch_hash' => self::EmailBatchHash,
        ]);
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source' => ChurchServiceSource::OpenLp,
            'batch_hash' => self::OpenLpBatchHash,
        ]);
        config()->set('church.historic_corpus.census_source_kinds', 'email,openlp');

        $this->artisan('services:proposal-census', [
            '--gate' => true,
            '--expectation' => [$this->expectationFile($email)],
        ])
            ->expectsOutputToContain('No manifest-derived expectation covers a source kind this census claims to cover')
            ->assertFailed();
    }

    #[Test]
    public function the_gate_option_fails_when_the_staged_corpus_falls_short_of_the_manifest(): void
    {
        $this->proposal();

        $this->artisan('services:proposal-census --gate --expected-services=40')
            ->expectsOutputToContain('Fewer services are staged than the approved manifest declares')
            ->assertFailed();
    }

    #[Test]
    public function it_emits_the_census_and_gate_as_json(): void
    {
        $this->proposal();

        $this->assertSame(0, Artisan::call('services:proposal-census', ['--json' => true]));

        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('custom:welcome', $decoded['classes'][0]['subject']);
        $this->assertFalse($decoded['gate']['passes']);
        $this->assertSame(1, $decoded['gate']['proposal_count']);
    }

    /**
     * The operator-facing half of F3: the corpus line has to say what it is made of,
     * or a reviewer reading "391 services staged, 391 projected" has no way to notice
     * that none of it is OpenLP.
     */
    #[Test]
    public function the_gate_option_fails_and_names_the_unstaged_source_kind(): void
    {
        $this->proposal();
        config()->set('church.historic_corpus.census_source_kinds', 'email,openlp');

        $this->artisan('services:proposal-census --gate')
            ->expectsOutputToContain('Staged services by source: email 1  openlp 0')
            ->expectsOutputToContain('A declared source kind has no staged services at all')
            ->assertFailed();
    }

    #[Test]
    public function the_gate_option_fails_when_no_source_kinds_are_declared(): void
    {
        $this->proposal();
        config()->set('church.historic_corpus.census_source_kinds', null);

        $this->artisan('services:proposal-census --gate')
            ->expectsOutputToContain('No valid source kinds are declared')
            ->assertFailed();
    }

    private function proposal(): ChurchServiceMergeProposal
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-09-01',
            'service' => 'morning',
            'needs_review' => true,
            'projection_policy_version' => ChurchServiceProjector::PROJECTION_POLICY_VERSION,
        ]);
        ChurchServiceSourceRecord::factory()->create(['church_service_id' => $service->id]);
        config()->set('church.historic_corpus.expected_services', 1);
        // The factory stages Email evidence, so an Email-scoped census is the one this
        // fixture can honestly claim. Declaring `openlp` here would be the F3 defect.
        config()->set('church.historic_corpus.census_source_kinds', 'email');

        return ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
            'field_decisions' => [['match_tier' => 2]],
            'conflicts' => [['kind' => 'ambiguous_repeat_match', 'canonical_identity' => 'custom:welcome']],
            'proposed_items' => [[
                'canonical_identity' => 'custom:welcome',
                'position' => 1,
                'type' => 'custom',
                'title' => 'Welcome',
            ]],
        ]);
    }

    /**
     * A one-lane expectation artifact covering exactly the record given, written to
     * disk so the command's own file reading is exercised rather than bypassed.
     */
    private function expectationFile(ChurchServiceSourceRecord $record): string
    {
        $expectation = [
            'format' => OosApprovedCorpus::Format,
            'version' => OosApprovedCorpus::Version,
            'source' => $record->source->value,
            'batch_key' => "{$record->source->value}-curated-test",
            'batch_hash' => (string) $record->batch_hash,
            'manifest_hash' => str_repeat('f', 64),
            'approved_sources' => [[
                'item_key' => $record->source_key,
                'origin' => explode('|', $record->source_key, 2)[0],
                'source_key' => $record->source_key,
                'input_hash' => (string) $record->input_hash,
                'identity' => [
                    'date' => $record->churchService->date->toDateString(),
                    'service' => $record->churchService->service->value,
                ],
                'content_scope' => 'full',
            ]],
        ];
        $expectation['expectation_hash'] = CanonicalJson::hash($expectation);

        $path = storage_path('scratch/expectation-'.bin2hex(random_bytes(6)).'.json');
        file_put_contents($path, CanonicalJson::encodeReadable($expectation));
        $this->artifacts[] = $path;

        return $path;
    }
}
