<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\DetectorCase;
use App\Enums\ChurchServiceItemSource;
use App\Enums\ProcessingStatus;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\CanonicalJson;
use App\Support\DetectorCatalogue;
use RuntimeException;

/**
 * Freeze §4.3a H3's case book: bind every authored case to the database, group
 * it by service, and hash the result.
 *
 * **Fail closed on identity.** A case whose run is missing, whose section sits
 * on another run, or whose sermon the run did not publish stops the freeze. The
 * alternative is scoring a detector on the wrong service, which is exactly what
 * the catalogue's run-for-sermon mix-up would have done.
 *
 * **Splits are per service group.** A service's sermon, songs and derived clips
 * share one `service_group_key` and so one split; a group that informed any fix
 * is `regression`, otherwise `development`. There is no reserved tier (H9).
 *
 * **H5's dimensions are recorded as stored, never re-measured.** Era boundaries
 * have not been derived yet, so `era` is null rather than a guessed calendar
 * band, and channel layout is not in the stored fingerprint, so it is marked
 * unstored rather than dropped.
 */
class FreezeDetectorCaseBook
{
    public const Format = 'crockenhill-detector-case-book';

    public const Version = 1;

    /**
     * @return array<string, mixed>
     */
    public function build(DetectorCaseBookSource $source): array
    {
        $cases = $source->cases();
        $runs = $this->runs($cases);
        $sections = ServiceSection::query()
            ->whereIn('id', array_filter(array_map(static fn (DetectorCase $case): ?int => $case->section, $cases)))
            ->pluck('media_processing_log_id', 'id');
        $independentServices = $this->servicesWithIndependentOrderOfService($runs);

        $frozen = [];

        foreach ($cases as $case) {
            $run = $runs[$case->run];
            $this->assertSubjectBelongsToRun($case, $run, $sections->all());
            $frozen[] = $this->freezeCase($case, $run, $independentServices);
        }

        $frozen = $this->withSplits($frozen);
        $adjudicated = count(array_filter($cases, static fn (DetectorCase $case): bool => $case->basis->isAdjudicated()));

        $artifact = [
            'format' => self::Format,
            'version' => self::Version,
            'private' => true,
            'inputs' => [
                'source_hash' => $source->hash(),
                'catalogue_version' => DetectorCatalogue::Version,
            ],
            'completeness' => [
                'cases' => count($frozen),
                'adjudicated_cases' => $adjudicated,
                'provisional_cases' => count($frozen) - $adjudicated,
                'eligible_cases' => count(array_filter($frozen, static fn (array $case): bool => $case['resolution']['eligible'])),
                'service_groups' => count(array_unique(array_column($frozen, 'service_group_key'))),
                'era_boundaries_measured' => false,
            ],
            'cases' => $frozen,
            'aggregates' => $source->aggregates(),
        ];
        $artifact['case_book_hash'] = CanonicalJson::hash($artifact);

        return $artifact;
    }

    /**
     * @param  list<DetectorCase>  $cases
     * @return array<int, MediaProcessingLog>
     */
    private function runs(array $cases): array
    {
        $wanted = array_values(array_unique(array_map(static fn (DetectorCase $case): int => $case->run, $cases)));
        $runs = MediaProcessingLog::query()->whereIn('id', $wanted)->get()->keyBy('id')->all();
        $missing = array_diff($wanted, array_keys($runs));

        if ($missing !== []) {
            sort($missing);

            throw new RuntimeException(
                'The case book names runs that could not be found: run '.implode(', run ', $missing)
                .'. Check nothing has repointed the database, such as a Dusk run in progress.'
            );
        }

        return $runs;
    }

    /**
     * @param  array<int, int>  $sectionRuns
     */
    private function assertSubjectBelongsToRun(DetectorCase $case, MediaProcessingLog $run, array $sectionRuns): void
    {
        if ($case->section !== null && ($sectionRuns[$case->section] ?? null) !== $run->id) {
            throw new RuntimeException("Case [{$case->caseId}] names section {$case->section}, which is not on run {$run->id}.");
        }

        if ($case->sermon !== null && $run->sermon_id !== $case->sermon) {
            throw new RuntimeException("Case [{$case->caseId}] names sermon {$case->sermon}, which run {$run->id} did not publish.");
        }
    }

    /**
     * @param  array<int, true>  $independentServices
     * @return array<string, mixed>
     */
    private function freezeCase(DetectorCase $case, MediaProcessingLog $run, array $independentServices): array
    {
        $historic = $run->historic_import_operation_id !== null;
        $import = (array) data_get($run->processing_metadata?->toArray() ?? [], 'historic_import', []);

        return [
            'case_id' => $case->caseId,
            'detector_id' => $case->detectorId,
            'reference' => $case->reference,
            'subject' => ['run' => $case->run, 'section' => $case->section, 'sermon' => $case->sermon, 'span' => $case->span],
            'service_group_key' => $run->church_service_id !== null
                ? "church_service:{$run->church_service_id}"
                : "run:{$run->id}",
            'resolution' => [
                'population' => $historic ? 'historic' : 'weekly',
                'status' => $run->status->value,
                'excluded' => $run->exclusionReason(),
                'eligible' => $historic && $run->status === ProcessingStatus::Completed && ! $run->isExcluded(),
            ],
            'dimensions' => [
                'era' => null,
                'codec_fingerprint' => is_string($import['codec_fingerprint'] ?? null) ? $import['codec_fingerprint'] : null,
                'channel_layout' => 'not_stored',
                'service_occasion' => $run->churchService?->occasion?->value,
                'corroboration_grade' => is_string($import['corroboration_grade'] ?? null) ? $import['corroboration_grade'] : null,
                'independent_order_of_service' => isset($independentServices[$run->church_service_id]),
            ],
            'truth' => [
                'value' => $case->truth->value,
                'basis' => $case->basis->value,
                'adjudicated' => $case->basis->isAdjudicated(),
                'evidence' => $case->evidence,
                'note' => $case->note,
            ],
            'exposure' => ['informed_fix' => $case->informedFix],
        ];
    }

    /**
     * @param  array<int, MediaProcessingLog>  $runs
     * @return array<int, true>
     */
    private function servicesWithIndependentOrderOfService(array $runs): array
    {
        $serviceIds = array_values(array_filter(array_map(
            static fn (MediaProcessingLog $run): ?int => $run->church_service_id,
            $runs,
        )));

        return array_fill_keys(
            ChurchServiceItem::query()
                ->whereIn('church_service_id', $serviceIds)
                ->whereIn('source', [
                    ChurchServiceItemSource::Email->value,
                    ChurchServiceItemSource::OpenLp->value,
                    ChurchServiceItemSource::Manual->value,
                ])
                ->distinct()
                ->pluck('church_service_id')
                ->all(),
            true,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @return list<array<string, mixed>>
     */
    private function withSplits(array $cases): array
    {
        $regressionGroups = [];

        foreach ($cases as $case) {
            if ($case['exposure']['informed_fix']) {
                $regressionGroups[$case['service_group_key']] = true;
            }
        }

        return array_map(static fn (array $case): array => [
            ...$case,
            'split' => isset($regressionGroups[$case['service_group_key']]) ? 'regression' : 'development',
        ], $cases);
    }
}
