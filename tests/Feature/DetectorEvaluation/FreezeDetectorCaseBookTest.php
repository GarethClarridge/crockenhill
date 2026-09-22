<?php

declare(strict_types=1);

namespace Tests\Feature\DetectorEvaluation;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ProcessingStatus;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\DetectorEvaluation\DetectorCaseBookSource;
use App\Services\DetectorEvaluation\FreezeDetectorCaseBook;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class FreezeDetectorCaseBookTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    public function test_it_binds_each_case_to_its_run_and_service_group(): void
    {
        $service = ChurchService::factory()->create();
        $run = $this->historicRun($service, [
            'codec_fingerprint' => 'h264:aac:1920x1080:30/1:48000',
            'corroboration_grade' => 'full',
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);

        $artifact = $this->freeze([$this->case($run->id)]);
        $case = $artifact['cases'][0];

        $this->assertSame("church_service:{$service->id}", $case['service_group_key']);
        $this->assertTrue($case['resolution']['eligible']);
        $this->assertSame('h264:aac:1920x1080:30/1:48000', $case['dimensions']['codec_fingerprint']);
        $this->assertSame('full', $case['dimensions']['corroboration_grade']);
        $this->assertTrue($case['dimensions']['independent_order_of_service']);
        $this->assertNull($case['dimensions']['era']);
        $this->assertSame('regression', $case['split']);
    }

    /**
     * A service with only the recording's own detected items has no second
     * view, which is the no-OoS group H5 reports on its own.
     */
    public function test_detected_items_are_not_an_independent_order_of_service(): void
    {
        $service = ChurchService::factory()->create();
        $run = $this->historicRun($service);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'source' => ChurchServiceItemSource::Livestream->value,
        ]);

        $artifact = $this->freeze([$this->case($run->id)]);

        $this->assertFalse($artifact['cases'][0]['dimensions']['independent_order_of_service']);
    }

    /**
     * Splits are assigned per service, never per case, so a service's sermon
     * and its songs cannot land on opposite sides.
     */
    public function test_a_service_group_takes_one_split(): void
    {
        $service = ChurchService::factory()->create();
        $run = $this->historicRun($service);

        $artifact = $this->freeze([
            $this->case($run->id, caseId: 'a', informedFix: true),
            $this->case($run->id, caseId: 'b', informedFix: false),
        ]);

        $this->assertSame(['regression', 'regression'], array_column($artifact['cases'], 'split'));
    }

    public function test_a_group_that_informed_no_fix_is_development(): void
    {
        $run = $this->historicRun(ChurchService::factory()->create());

        $artifact = $this->freeze([$this->case($run->id, informedFix: false)]);

        $this->assertSame('development', $artifact['cases'][0]['split']);
    }

    /**
     * An excluded run stays in the book, because it is still a regression
     * fixture, but it must not be counted in the eligible population.
     */
    public function test_an_excluded_run_is_recorded_as_ineligible(): void
    {
        $run = $this->historicRun(ChurchService::factory()->create());
        $run->forceFill(['processing_metadata' => [
            ...$run->processing_metadata->toArray(),
            'exclusion' => ['reason' => MediaProcessingLog::EXCLUSION_REASONS[0]],
        ]])->save();

        $artifact = $this->freeze([$this->case($run->id)]);

        $this->assertFalse($artifact['cases'][0]['resolution']['eligible']);
        $this->assertSame(0, $artifact['completeness']['eligible_cases']);
    }

    /**
     * A run whose service has gone keeps its own group, rather than every such
     * run collapsing into one shared null group.
     */
    public function test_a_run_without_a_service_is_its_own_group(): void
    {
        $run = $this->historicRun(null);

        $artifact = $this->freeze([$this->case($run->id)]);

        $this->assertSame("run:{$run->id}", $artifact['cases'][0]['service_group_key']);
    }

    /**
     * Named cases that cannot be found are an error, never a quietly smaller
     * book: a run is invisible whenever something has repointed the database.
     */
    public function test_it_refuses_a_case_whose_run_does_not_exist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('run 999999');

        $this->freeze([$this->case(999999)]);
    }

    public function test_it_refuses_a_section_that_belongs_to_another_run(): void
    {
        $run = $this->historicRun(ChurchService::factory()->create());
        $other = $this->historicRun(ChurchService::factory()->create());
        $section = ServiceSection::factory()->create(['media_processing_log_id' => $other->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("section {$section->id}");

        $this->freeze([$this->case($run->id, section: $section->id)]);
    }

    /**
     * The failure the case book was built to stop: sermon and run ids share one
     * numeric range, and the catalogue once named sermons as runs.
     */
    public function test_it_refuses_a_sermon_the_run_did_not_publish(): void
    {
        $run = $this->historicRun(ChurchService::factory()->create());
        $sermon = Sermon::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("sermon {$sermon->id}");

        $this->freeze([$this->case($run->id, sermon: $sermon->id)]);
    }

    public function test_it_counts_adjudicated_and_provisional_truth_separately(): void
    {
        $run = $this->historicRun(ChurchService::factory()->create());

        $artifact = $this->freeze([
            $this->case($run->id, caseId: 'heard', basis: 'source_reviewed'),
            $this->case($run->id, caseId: 'redecoded', basis: 'source_redecode'),
        ]);

        $this->assertSame(1, $artifact['completeness']['adjudicated_cases']);
        $this->assertSame(1, $artifact['completeness']['provisional_cases']);
    }

    public function test_the_artifact_hash_covers_the_content(): void
    {
        $run = $this->historicRun(ChurchService::factory()->create());
        $artifact = $this->freeze([$this->case($run->id)]);
        $recorded = $artifact['case_book_hash'];
        unset($artifact['case_book_hash']);

        $this->assertSame(CanonicalJson::hash($artifact), $recorded);
    }

    public function test_the_command_writes_a_private_artifact_and_refuses_to_overwrite(): void
    {
        $run = $this->historicRun(ChurchService::factory()->create());
        $source = tempnam(sys_get_temp_dir(), 'case-book-source');
        $output = sys_get_temp_dir().'/case-book-'.uniqid().'.json';
        file_put_contents($source, json_encode($this->document([$this->case($run->id)])));

        try {
            $this->artisan('detectors:freeze-case-book', ['--source' => $source, '--output' => $output])
                ->assertSuccessful();

            $this->assertSame('0600', substr(sprintf('%o', fileperms($output)), -4));

            $this->artisan('detectors:freeze-case-book', ['--source' => $source, '--output' => $output])
                ->expectsOutputToContain('Refusing to overwrite')
                ->assertFailed();
        } finally {
            @unlink($source);
            @unlink($output);
        }
    }

    public function test_the_command_requires_an_absolute_output_path(): void
    {
        $this->artisan('detectors:freeze-case-book', ['--output' => 'relative.json'])
            ->expectsOutputToContain('absolute')
            ->assertFailed();
    }

    /**
     * @param  array<string, mixed>|null  $historic
     */
    private function historicRun(?ChurchService $service, ?array $historic = null): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Completed,
            'church_service_id' => $service?->id,
            'historic_import_operation_id' => $this->createHistoricImportOperation()->id,
            'processing_metadata' => ['historic_import' => $historic ?? []],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function case(
        int $run,
        string $caseId = 'structure-macro-section/run',
        ?int $section = null,
        ?int $sermon = null,
        bool $informedFix = true,
        string $basis = 'source_reviewed',
    ): array {
        return [
            'case_id' => $caseId,
            'detector_id' => 'structure-macro-section',
            'reference' => null,
            'subject' => ['run' => $run, 'section' => $section, 'sermon' => $sermon],
            'truth' => 'defective',
            'basis' => $basis,
            'evidence' => 'test fixture',
            'informed_fix' => $informedFix,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @return array<string, mixed>
     */
    private function document(array $cases): array
    {
        return [
            'format' => DetectorCaseBookSource::Format,
            'version' => DetectorCaseBookSource::Version,
            'cases' => $cases,
            'aggregates' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @return array<string, mixed>
     */
    private function freeze(array $cases): array
    {
        return app(FreezeDetectorCaseBook::class)->build(DetectorCaseBookSource::fromArray($this->document($cases)));
    }
}
