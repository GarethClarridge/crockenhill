<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\DetectorSeverity;
use RuntimeException;
use Throwable;

/**
 * The acceptance thresholds a detector must meet, read from a checked-in,
 * hash-bound contract.
 *
 * **Why a file and not `config()`.** A threshold behind `env()` is not a
 * predeclared threshold: anyone could move it after seeing a candidate's
 * results, which is the single failure predeclaration exists to prevent. Here
 * changing a number is a commit with a diff, and the recorded hash has to be
 * regenerated to match — so a quiet edit fails closed instead of silently
 * lowering the bar.
 *
 * **What the binding covers.** The hash is taken over the contract with its own
 * `hash` key removed, so the numbers cannot drift from their recorded digest.
 * The catalogue version is checked separately, against
 * {@see DetectorCatalogue::Version}: adding a detector invalidates an acceptance
 * claim made before that detector existed, because the claim was measured over a
 * different set. A hash alone could not catch that — the file would be
 * untouched and still stale.
 *
 * Every failure raises. A thresholds file that cannot be verified must stop an
 * evaluation, never degrade into scoring against defaults nobody declared.
 */
class DetectorAcceptanceThresholds
{
    public const Format = 'crockenhill-detector-acceptance-thresholds';

    public const Version = 1;

    public const DefaultPath = 'resources/detector-acceptance-thresholds.json';

    /**
     * @param  array<string, mixed>  $contract
     */
    private function __construct(private readonly array $contract) {}

    public static function load(?string $path = null): self
    {
        $path = $path ?? base_path(self::DefaultPath);

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Detector acceptance thresholds are not readable at {$path}.");
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Detector acceptance thresholds could not be read at {$path}.");
        }

        try {
            $contract = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Detector acceptance thresholds are not valid JSON.', previous: $exception);
        }

        if (! is_array($contract)) {
            throw new RuntimeException('Detector acceptance thresholds must contain a JSON object.');
        }

        self::assertBinding($contract);

        return new self($contract);
    }

    /**
     * The thresholds for one severity, or null when that severity is
     * reporting-only and has nothing to meet.
     *
     * @return array{recall_floor: float, false_positive_ceiling: float, non_inferiority_margin: float}|null
     */
    public function forSeverity(DetectorSeverity $severity): ?array
    {
        $declared = $this->contract['severities'][$severity->value] ?? null;

        if (! is_array($declared)) {
            throw new RuntimeException("No thresholds are declared for severity [{$severity->value}].");
        }

        if (($declared['scored'] ?? null) !== true) {
            return null;
        }

        foreach (['recall_floor', 'false_positive_ceiling', 'non_inferiority_margin'] as $key) {
            if (! is_numeric($declared[$key] ?? null)) {
                throw new RuntimeException("Severity [{$severity->value}] declares no {$key}.");
            }
        }

        return [
            'recall_floor' => (float) $declared['recall_floor'],
            'false_positive_ceiling' => (float) $declared['false_positive_ceiling'],
            'non_inferiority_margin' => (float) $declared['non_inferiority_margin'],
        ];
    }

    /**
     * Whether every applicable confirmed regression defect must be prevented or
     * contained regardless of a candidate's rates.
     */
    public function regressionDefectsMustAllPass(): bool
    {
        return ($this->contract['regression_defects_must_all_pass'] ?? null) === true;
    }

    /**
     * Whether recall may be pooled across detectors measured in different units.
     *
     * Declared false: defective minutes and defective sermons are not addable.
     */
    public function allowsAggregationAcrossUnits(): bool
    {
        return ($this->contract['recall']['aggregation_across_units'] ?? null) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function reviewBurden(): array
    {
        $burden = $this->contract['review_burden'] ?? null;

        if (! is_array($burden)) {
            throw new RuntimeException('Detector acceptance thresholds declare no review burden basis.');
        }

        return $burden;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->contract;
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private static function assertBinding(array $contract): void
    {
        if (($contract['format'] ?? null) !== self::Format || ($contract['version'] ?? null) !== self::Version) {
            throw new RuntimeException('Detector acceptance thresholds have an unsupported format or version.');
        }

        if (($contract['catalogue_version'] ?? null) !== DetectorCatalogue::Version) {
            throw new RuntimeException(
                'Detector acceptance thresholds were declared against catalogue version '
                .var_export($contract['catalogue_version'] ?? null, true)
                .', but the catalogue is at version '.DetectorCatalogue::Version
                .'. Re-declare the thresholds over the current detector set rather than reusing a claim '
                .'measured over a different one.'
            );
        }

        $recorded = $contract['hash'] ?? null;
        unset($contract['hash']);

        if (! is_string($recorded) || ! hash_equals($recorded, CanonicalJson::hash($contract))) {
            throw new RuntimeException(
                'Detector acceptance thresholds do not match their recorded hash. A threshold was edited '
                .'without re-declaring it; refusing to score against unverified numbers.'
            );
        }
    }
}
