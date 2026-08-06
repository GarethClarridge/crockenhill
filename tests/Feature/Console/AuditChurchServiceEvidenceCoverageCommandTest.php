<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceSourceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §12.4 requires three production counts before current-era re-projection can be
 * scheduled: services, services carrying at least one non-Manual source record,
 * and proposals carrying a resolver. The local dry run found 407 of 408 services
 * with no evidence at all, and the plan says plainly that local is not a
 * production copy — so the decision rests on numbers nothing could report.
 */
class AuditChurchServiceEvidenceCoverageCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_the_three_counts_section_12_4_requires(): void
    {
        $evidenced = ChurchService::factory()->create();
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $evidenced->id,
            'source' => ChurchServiceSource::Email,
        ]);

        $manualOnly = ChurchService::factory()->create();
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $manualOnly->id,
            'source' => ChurchServiceSource::Manual,
        ]);

        ChurchService::factory()->create();

        $resolver = User::factory()->create();
        ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $evidenced->id,
            'status' => ChurchServiceProposalStatus::Accepted,
            'resolved_by_user_id' => $resolver->id,
            'resolved_at' => now(),
        ]);
        ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $evidenced->id,
            'status' => ChurchServiceProposalStatus::Pending,
        ]);

        $this->artisan('audit:service-evidence-coverage')->assertSuccessful();

        $report = $this->jsonReport();

        $this->assertSame(3, $report['services']['total']);
        $this->assertSame(1, $report['services']['with_non_manual_source_record']);
        $this->assertSame(1, $report['proposals']['with_resolver']);
    }

    #[Test]
    public function it_separates_manual_only_services_from_services_with_no_evidence_at_all(): void
    {
        $manualOnly = ChurchService::factory()->create();
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $manualOnly->id,
            'source' => ChurchServiceSource::Manual,
        ]);

        ChurchService::factory()->count(2)->create();

        $this->artisan('audit:service-evidence-coverage')->assertSuccessful();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['services']['with_any_source_record']);
        $this->assertSame(0, $report['services']['with_non_manual_source_record']);
        $this->assertSame(1, $report['services']['with_manual_source_records_only']);
        $this->assertSame(2, $report['services']['without_any_source_record']);
    }

    /**
     * The population §12.4 says the plan has never asked about: a canonical result
     * that no retained evidence describes, so it can never be re-derived, audited
     * or converged from sources. Success criterion 1 presumes it does not exist.
     */
    #[Test]
    public function it_counts_unevidenced_services_that_nonetheless_hold_canonical_items(): void
    {
        ChurchService::factory()->create();

        $withItems = ChurchService::factory()->create();
        ChurchServiceItem::factory()->count(2)->create([
            'church_service_id' => $withItems->id,
        ]);

        $this->artisan('audit:service-evidence-coverage')->assertSuccessful();

        $report = $this->jsonReport();

        $this->assertSame(2, $report['services']['without_any_source_record']);
        $this->assertSame(1, $report['services']['unevidenced_with_canonical_items']);
        $this->assertSame(2, $report['items']['on_unevidenced_services']);
    }

    #[Test]
    public function it_breaks_source_records_down_by_kind(): void
    {
        $service = ChurchService::factory()->create();

        foreach ([ChurchServiceSource::Email, ChurchServiceSource::OpenLp, ChurchServiceSource::OpenLp] as $source) {
            ChurchServiceSourceRecord::factory()->create([
                'church_service_id' => $service->id,
                'source' => $source,
            ]);
        }

        $this->artisan('audit:service-evidence-coverage')->assertSuccessful();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['source_records']['email']);
        $this->assertSame(2, $report['source_records']['openlp']);
        $this->assertSame(0, $report['source_records']['livestream']);
        $this->assertSame(0, $report['source_records']['manual']);
        $this->assertSame(3, $report['source_records']['total']);
    }

    /**
     * B13 recorded dispositions no human made. §12.4 reverses those rather than
     * inheriting them, so the triage set has to be countable before the run.
     */
    #[Test]
    public function it_counts_the_b13_triage_population_by_resolver_and_decision_rule(): void
    {
        $service = ChurchService::factory()->create();
        $resolver = User::factory()->create();

        ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
            'status' => ChurchServiceProposalStatus::Accepted,
            'resolved_by_user_id' => $resolver->id,
            'resolved_at' => now(),
        ]);

        ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
            'status' => ChurchServiceProposalStatus::Accepted,
            'resolved_by_user_id' => null,
            'resolved_at' => null,
        ]);

        ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
            'status' => ChurchServiceProposalStatus::Rejected,
            'resolved_by_user_id' => $resolver->id,
            'resolved_at' => now(),
        ]);

        $this->artisan('audit:service-evidence-coverage')->assertSuccessful();

        $report = $this->jsonReport();

        $this->assertSame(3, $report['proposals']['total']);
        $this->assertSame(2, $report['proposals']['by_status']['accepted']);
        $this->assertSame(1, $report['proposals']['by_status']['rejected']);
        $this->assertSame(2, $report['proposals']['with_resolver']);
        $this->assertSame(1, $report['proposals']['resolved_without_resolver']);
        $this->assertSame(0, $report['proposals']['with_decision_rule']);
    }

    #[Test]
    public function it_reports_projection_coverage_from_the_shared_completeness_service(): void
    {
        $service = ChurchService::factory()->create();
        ChurchServiceSourceRecord::factory()->create(['church_service_id' => $service->id]);

        $this->artisan('audit:service-evidence-coverage')->assertSuccessful();

        $report = $this->jsonReport();

        $this->assertSame(1, $report['projection']['staged_services']);
        $this->assertArrayHasKey('projected_services', $report['projection']);
        $this->assertArrayHasKey('stale_projection_services', $report['projection']);
        $this->assertArrayHasKey('policy_version', $report['projection']);
    }

    /**
     * The report is dispatched into a public GitHub Actions log. Counts only —
     * never a date, a service id, a source key or a filename.
     */
    #[Test]
    public function it_prints_counts_only_and_never_identifies_a_service(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2019-08-04',
            'original_filename' => '2019-08-04 AM.osz',
        ]);
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $service->id,
            'source_key' => 'oos-email-message-id-12345',
        ]);

        $output = $this->runAndCaptureOutput('audit:service-evidence-coverage');

        $this->assertStringNotContainsString('2019-08-04', $output);
        $this->assertStringNotContainsString('oos-email-message-id-12345', $output);
        $this->assertStringNotContainsString('.osz', $output);

        // Deliberately not asserted: the absence of the service id. A count and an
        // id are both small integers, so such an assertion would fail on "1" as a
        // total and pass only by accident. The leak risk here is the identifying
        // *strings* above, and the command has no code path that prints a row.
        $this->assertNotNull($service->id);
    }

    #[Test]
    public function it_succeeds_on_an_empty_database_without_dividing_by_zero(): void
    {
        $this->artisan('audit:service-evidence-coverage')->assertSuccessful();

        $report = $this->jsonReport();

        $this->assertSame(0, $report['services']['total']);
        $this->assertNull($report['services']['non_manual_coverage_percent']);
    }

    /** @return array<string, mixed> */
    private function jsonReport(): array
    {
        $decoded = json_decode($this->runAndCaptureOutput('audit:service-evidence-coverage --json'), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function runAndCaptureOutput(string $command): string
    {
        Artisan::call($command);

        return Artisan::output();
    }
}
