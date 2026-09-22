<?php

declare(strict_types=1);

namespace App\Services\DetectorEvaluation;

use App\Data\DetectorCase;
use App\Enums\DetectorCaseBasis;
use App\Enums\DetectorCaseTruth;
use App\Enums\DetectorSurface;
use App\Support\CanonicalJson;
use App\Support\DetectorCatalogue;
use InvalidArgumentException;
use JsonException;

/**
 * The checked-in, authored form of §4.3a H3's case book.
 *
 * **Why a checked-in file.** The truth a detector is scored against is a
 * predeclaration in the same sense as its thresholds: a label changed after
 * seeing a candidate's results is not a label. Here every adjudication is a line
 * in a reviewable diff, and each names the register or plan row it came from.
 * {@see FreezeDetectorCaseBook} then binds it to the database and hashes it.
 *
 * **Cases and aggregates.** A case is one subject with one truth. An aggregate
 * is a census count the plan never adjudicated item by item, such as "245 of 464
 * clips". It discharges its catalogue reference honestly as a count, rather than
 * inventing per-item truth, and contributes nothing to any rate.
 *
 * Validation is structural and against the catalogue only. Whether the subjects
 * exist is the freezer's question, because it needs the database.
 */
class DetectorCaseBookSource
{
    public const Format = 'crockenhill-detector-case-book-source';

    public const Version = 1;

    public const DefaultPath = 'resources/detector-case-book.json';

    /**
     * @param  list<DetectorCase>  $cases
     * @param  list<array{detector_id: string, reference: string, register: string, reason: string}>  $aggregates
     * @param  array<string, mixed>  $document
     */
    private function __construct(
        private readonly array $cases,
        private readonly array $aggregates,
        private readonly array $document,
    ) {}

    public static function load(?string $path = null): self
    {
        $path ??= base_path(self::DefaultPath);
        $contents = is_readable($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            throw new InvalidArgumentException("The detector case book is not readable at {$path}.");
        }

        try {
            $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The detector case book is not valid JSON.', previous: $exception);
        }

        if (! is_array($document)) {
            throw new InvalidArgumentException('The detector case book must contain a JSON object.');
        }

        return self::fromArray($document);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function fromArray(array $document): self
    {
        if (($document['format'] ?? null) !== self::Format || ($document['version'] ?? null) !== self::Version) {
            throw new InvalidArgumentException('The detector case book has the wrong format or version.');
        }

        $cases = [];

        foreach ((array) ($document['cases'] ?? []) as $row) {
            $case = self::caseFrom(is_array($row) ? $row : []);

            if (isset($cases[$case->caseId])) {
                throw new InvalidArgumentException("Duplicate case id [{$case->caseId}].");
            }

            $cases[$case->caseId] = $case;
        }

        $aggregates = array_map(self::aggregateFrom(...), array_values((array) ($document['aggregates'] ?? [])));

        return new self(array_values($cases), $aggregates, $document);
    }

    /**
     * @return list<DetectorCase>
     */
    public function cases(): array
    {
        return $this->cases;
    }

    /**
     * @return list<array{detector_id: string, reference: string, register: string, reason: string}>
     */
    public function aggregates(): array
    {
        return $this->aggregates;
    }

    public function hash(): string
    {
        return CanonicalJson::hash($this->document);
    }

    /**
     * The catalogue references each detector's cases and aggregates discharge.
     *
     * @return array<string, list<string>>
     */
    public function dischargedReferences(): array
    {
        $discharged = [];

        foreach ($this->cases as $case) {
            if ($case->reference !== null) {
                $discharged[$case->detectorId][] = $case->reference;
            }
        }

        foreach ($this->aggregates as $aggregate) {
            $discharged[$aggregate['detector_id']][] = $aggregate['reference'];
        }

        return $discharged;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function caseFrom(array $row): DetectorCase
    {
        $caseId = self::requiredString($row, 'case_id', 'a case');
        $detectorId = self::detectorId($row, "Case [{$caseId}]");
        $subject = is_array($row['subject'] ?? null) ? $row['subject'] : [];

        if (! is_int($subject['run'] ?? null)) {
            throw new InvalidArgumentException("Case [{$caseId}] names no run.");
        }

        $truth = DetectorCaseTruth::tryFrom((string) ($row['truth'] ?? ''))
            ?? throw new InvalidArgumentException("Case [{$caseId}] has an unknown truth.");
        $basis = DetectorCaseBasis::tryFrom((string) ($row['basis'] ?? ''))
            ?? throw new InvalidArgumentException("Case [{$caseId}] has an unknown basis.");

        return new DetectorCase(
            caseId: $caseId,
            detectorId: $detectorId,
            reference: is_string($row['reference'] ?? null) ? $row['reference'] : null,
            run: $subject['run'],
            section: is_int($subject['section'] ?? null) ? $subject['section'] : null,
            sermon: is_int($subject['sermon'] ?? null) ? $subject['sermon'] : null,
            truth: $truth,
            basis: $basis,
            evidence: self::requiredString($row, 'evidence', "Case [{$caseId}]"),
            informedFix: ($row['informed_fix'] ?? null) === true,
            note: is_string($row['note'] ?? null) ? $row['note'] : null,
            span: self::span($subject['span'] ?? null, $detectorId, $caseId),
        );
    }

    /** @return array{start: float, end: float}|null */
    private static function span(mixed $span, string $detectorId, string $caseId): ?array
    {
        if ($span === null) {
            return null;
        }

        if (! is_array($span)
            || ! is_numeric($span['start'] ?? null)
            || ! is_numeric($span['end'] ?? null)
            || ! is_finite((float) $span['start'])
            || ! is_finite((float) $span['end'])
            || (float) $span['start'] < 0
            || (float) $span['end'] <= (float) $span['start']
            || DetectorCatalogue::find($detectorId)?->surface !== DetectorSurface::SuspectTranscriptBlock) {
            throw new InvalidArgumentException("Case [{$caseId}] has an invalid source-timeline span or a surface without timed signals.");
        }

        return ['start' => (float) $span['start'], 'end' => (float) $span['end']];
    }

    /**
     * @return array{detector_id: string, reference: string, register: string, reason: string}
     */
    private static function aggregateFrom(mixed $row): array
    {
        $row = is_array($row) ? $row : [];

        return [
            'detector_id' => self::detectorId($row, 'An aggregate'),
            'reference' => self::requiredString($row, 'reference', 'An aggregate'),
            'register' => self::requiredString($row, 'register', 'An aggregate'),
            'reason' => self::requiredString($row, 'reason', 'An aggregate'),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function detectorId(array $row, string $owner): string
    {
        $detectorId = self::requiredString($row, 'detector_id', $owner);

        if (DetectorCatalogue::find($detectorId) === null) {
            throw new InvalidArgumentException("{$owner} names detector [{$detectorId}], which is not catalogued.");
        }

        return $detectorId;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function requiredString(array $row, string $key, string $owner): string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$owner} has no {$key}.");
        }

        return $value;
    }
}
