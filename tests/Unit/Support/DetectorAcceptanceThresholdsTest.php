<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\DetectorSeverity;
use App\Support\CanonicalJson;
use App\Support\DetectorAcceptanceThresholds;
use App\Support\DetectorCatalogue;
use RuntimeException;
use Tests\TestCase;

class DetectorAcceptanceThresholdsTest extends TestCase
{
    public function test_it_loads_the_checked_in_contract(): void
    {
        $thresholds = DetectorAcceptanceThresholds::load();

        $this->assertTrue($thresholds->regressionDefectsMustAllPass());
        $this->assertFalse(
            $thresholds->allowsAggregationAcrossUnits(),
            'Defective minutes and defective sermons are not addable.'
        );
    }

    public function test_it_declares_the_severity_thresholds_the_plan_predeclared(): void
    {
        $thresholds = DetectorAcceptanceThresholds::load();

        $this->assertSame(
            ['recall_floor' => 0.95, 'false_positive_ceiling' => 0.02, 'non_inferiority_margin' => 0.02],
            $thresholds->forSeverity(DetectorSeverity::PublishedWrongContent),
        );
        $this->assertSame(
            ['recall_floor' => 0.9, 'false_positive_ceiling' => 0.05, 'non_inferiority_margin' => 0.03],
            $thresholds->forSeverity(DetectorSeverity::ContentLost),
        );
        $this->assertSame(
            ['recall_floor' => 0.8, 'false_positive_ceiling' => 0.1, 'non_inferiority_margin' => 0.05],
            $thresholds->forSeverity(DetectorSeverity::WrongMetadata),
        );
    }

    public function test_a_reporting_only_severity_has_nothing_to_meet(): void
    {
        $this->assertNull(
            DetectorAcceptanceThresholds::load()->forSeverity(DetectorSeverity::TechnicalQuality),
        );
    }

    /**
     * The point of the hash: a threshold quietly lowered after seeing a
     * candidate's results must stop the evaluation, not pass unnoticed.
     */
    public function test_it_refuses_a_contract_whose_numbers_were_edited(): void
    {
        $path = $this->writeContract(function (array $contract): array {
            $contract['severities']['s1']['recall_floor'] = 0.5;

            return $contract;
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('do not match their recorded hash');

        DetectorAcceptanceThresholds::load($path);
    }

    /**
     * A hash cannot catch this one: the file is untouched, but the detector set
     * it was measured over has changed.
     */
    public function test_it_refuses_a_contract_declared_against_another_catalogue_version(): void
    {
        $path = $this->writeContract(function (array $contract): array {
            $contract['catalogue_version'] = DetectorCatalogue::Version + 1;

            return $contract;
        }, rehash: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Re-declare the thresholds over the current detector set');

        DetectorAcceptanceThresholds::load($path);
    }

    public function test_it_refuses_an_unreadable_contract(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not readable');

        DetectorAcceptanceThresholds::load(sys_get_temp_dir().'/no-such-thresholds.json');
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function writeContract(callable $mutate, bool $rehash = false): string
    {
        $contract = json_decode(
            (string) file_get_contents(base_path(DetectorAcceptanceThresholds::DefaultPath)),
            true,
        );

        $this->assertIsArray($contract);
        $contract = $mutate($contract);

        if ($rehash) {
            $withoutHash = $contract;
            unset($withoutHash['hash']);
            $contract['hash'] = CanonicalJson::hash($withoutHash);
        }

        $path = tempnam(sys_get_temp_dir(), 'thresholds').'.json';
        file_put_contents($path, CanonicalJson::encodeReadable($contract));

        return $path;
    }
}
