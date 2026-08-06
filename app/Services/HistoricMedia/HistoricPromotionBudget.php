<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

/**
 * Turns measured promotion timings into the numeric production-window budget
 * §15.2 requires G7 to accept.
 *
 * §13.4 is explicit that this is a *separate* benchmark from the bulk media
 * pass: "local Whisper/AI throughput is not a proxy for production apply time".
 * Promotion runs on bundles, database rows and asset copies, and nothing about
 * how long a recording took to transcribe predicts how long applying its result
 * takes. Every input here comes from an observed promotion, never a forecast.
 *
 * The accepted cap is an input, not an output. Only the maintainer may accept
 * `maximum_import_ingress_blocked_minutes`; this class says whether the measured
 * reality fits inside the one they accepted.
 *
 * @phpstan-type Percentiles array{p50: float|null, p95: float|null, max: float|null, count: int}
 */
final class HistoricPromotionBudget
{
    /**
     * §15.2's admission floor: the command stops admitting new services when
     * remaining time equals the greater of this and the accepted p95 closeout
     * and resume duration.
     */
    private const MINIMUM_ADMISSION_FLOOR_MINUTES = 15.0;

    private const BYTES_PER_MIB = 1_048_576;

    /**
     * Nearest-rank, deliberately, rather than a linear-interpolation percentile.
     * With the handful of samples a rehearsal produces, interpolation invents a
     * p95 that no service actually took, and it always invents a *smaller* one
     * than the worst observed case. A window budget must be built from durations
     * that really happened.
     *
     * @param  list<float>  $samples
     * @return Percentiles
     */
    public function percentiles(array $samples): array
    {
        if ($samples === []) {
            return ['p50' => null, 'p95' => null, 'max' => null, 'count' => 0];
        }

        sort($samples);
        $count = count($samples);

        return [
            'p50' => $this->nearestRank($samples, 0.50),
            'p95' => $this->nearestRank($samples, 0.95),
            'max' => $samples[$count - 1],
            'count' => $count,
        ];
    }

    /**
     * @param  list<float>  $applySeconds  per-service apply durations
     * @param  list<float>  $preflightSeconds  whole-batch no-write preflight durations
     * @param  list<float>  $closeoutSeconds  exact audit plus no-op rerun durations
     * @param  list<float>  $rollbackSeconds  compensation and ingress-reopen durations
     * @param  list<int>  $assetBytes  bytes actually written to destinations
     * @param  list<float>  $assetSeconds  seconds spent writing them
     * @return array{
     *     accepted_cap_minutes: int,
     *     maximum_import_ingress_blocked_minutes: int,
     *     per_service_apply_seconds: Percentiles,
     *     preflight_seconds: Percentiles,
     *     closeout_seconds: Percentiles,
     *     rollback_seconds: Percentiles,
     *     preflight_reserve_minutes: float|null,
     *     closeout_reserve_minutes: float|null,
     *     rollback_reserve_minutes: float|null,
     *     applying_budget_minutes: float|null,
     *     services_per_window: int|null,
     *     admission_floor_minutes: float|null,
     *     latest_safe_start_before_window_end_minutes: float|null,
     *     asset_copy_mib_per_second: float|null,
     *     asset_bytes_written: int,
     *     missing_measurements: list<string>,
     *     warnings: list<string>,
     *     acceptable: bool,
     * }
     */
    public function report(
        array $applySeconds,
        array $preflightSeconds,
        array $closeoutSeconds,
        array $rollbackSeconds,
        array $assetBytes,
        array $assetSeconds,
        int $acceptedCapMinutes,
    ): array {
        $apply = $this->percentiles($applySeconds);
        $preflight = $this->percentiles($preflightSeconds);
        $closeout = $this->percentiles($closeoutSeconds);
        $rollback = $this->percentiles($rollbackSeconds);

        $preflightReserve = $this->minutes($preflight['p95']);
        $closeoutReserve = $this->minutes($closeout['p95']);
        $rollbackReserve = $this->minutes($rollback['p95']);

        $missing = $this->missingMeasurements($apply, $preflight, $closeout, $rollback);

        $applyingBudget = $this->applyingBudgetMinutes(
            $acceptedCapMinutes,
            $preflightReserve,
            $closeoutReserve,
            $rollbackReserve,
        );

        $applyP95Minutes = $this->minutes($apply['p95']);
        $servicesPerWindow = $applyingBudget === null || $applyP95Minutes === null || $applyP95Minutes <= 0.0
            ? null
            : (int) floor($applyingBudget / $applyP95Minutes);

        $admissionFloor = $closeoutReserve === null
            ? null
            : max(self::MINIMUM_ADMISSION_FLOOR_MINUTES, $closeoutReserve);

        $warnings = [];

        if ($servicesPerWindow === 0) {
            $warnings[] = 'The accepted window cannot fit one service at the measured p95 apply time.';
        }

        return [
            'accepted_cap_minutes' => $acceptedCapMinutes,
            'maximum_import_ingress_blocked_minutes' => $acceptedCapMinutes,
            'per_service_apply_seconds' => $apply,
            'preflight_seconds' => $preflight,
            'closeout_seconds' => $closeout,
            'rollback_seconds' => $rollback,
            'preflight_reserve_minutes' => $preflightReserve,
            'closeout_reserve_minutes' => $closeoutReserve,
            'rollback_reserve_minutes' => $rollbackReserve,
            'applying_budget_minutes' => $applyingBudget,
            'services_per_window' => $servicesPerWindow,
            'admission_floor_minutes' => $admissionFloor,
            // The last moment a new service may be admitted, expressed as minutes
            // before the window closes: its own p95 apply time plus whatever
            // rolling it back would cost if it fails.
            'latest_safe_start_before_window_end_minutes' => $applyP95Minutes === null || $rollbackReserve === null
                ? null
                : round($applyP95Minutes + $rollbackReserve, 2),
            'asset_copy_mib_per_second' => $this->throughput($assetBytes, $assetSeconds),
            'asset_bytes_written' => array_sum($assetBytes),
            'missing_measurements' => $missing,
            'warnings' => $warnings,
            'acceptable' => $missing === [] && $warnings === [],
        ];
    }

    /**
     * The cap less everything in the window that is not applying a service:
     * the preflight that must complete before the first one, and the closeout
     * and rollback reserve that must still fit after the last one.
     */
    private function applyingBudgetMinutes(
        int $acceptedCapMinutes,
        ?float $preflightReserve,
        ?float $closeoutReserve,
        ?float $rollbackReserve,
    ): ?float {
        if ($preflightReserve === null || $closeoutReserve === null || $rollbackReserve === null) {
            return null;
        }

        return round(
            max(0.0, $acceptedCapMinutes - $preflightReserve - $closeoutReserve - $rollbackReserve),
            2,
        );
    }

    /**
     * @param  Percentiles  $apply
     * @param  Percentiles  $preflight
     * @param  Percentiles  $closeout
     * @param  Percentiles  $rollback
     * @return list<string>
     */
    private function missingMeasurements(array $apply, array $preflight, array $closeout, array $rollback): array
    {
        $missing = [];

        foreach ([
            'per-service apply time' => $apply,
            'preflight time' => $preflight,
            'closeout time' => $closeout,
            'rollback recovery time' => $rollback,
        ] as $label => $percentiles) {
            if ($percentiles['count'] === 0) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * @param  list<int>  $assetBytes
     * @param  list<float>  $assetSeconds
     */
    private function throughput(array $assetBytes, array $assetSeconds): ?float
    {
        $bytes = array_sum($assetBytes);
        $seconds = array_sum($assetSeconds);

        if ($bytes === 0 || $seconds <= 0.0) {
            return null;
        }

        return round($bytes / self::BYTES_PER_MIB / $seconds, 3);
    }

    /** @param list<float> $sorted */
    private function nearestRank(array $sorted, float $quantile): float
    {
        $rank = (int) ceil($quantile * count($sorted));

        return $sorted[max(1, $rank) - 1];
    }

    private function minutes(?float $seconds): ?float
    {
        return $seconds === null ? null : round($seconds / 60, 2);
    }
}
