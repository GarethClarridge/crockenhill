<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Enums\SermonService;
use App\Support\Xlsx\XlsxReader;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Reads an original hymn-database workbook into flat usage statements.
 *
 * The source is a crosstab, not a row list: each year sheet puts hymns down the rows
 * (`Number`, `Category`, `Title`, `Total`, then one running total per year) and one
 * service date across the columns. A mark in a cell says that hymn was sung at that
 * service, and the mark itself carries the service:
 *
 * - `a` — morning
 * - `p` — evening
 * - `●` — the 2004-2008 sheets, which recorded the date only
 *
 * That marker is the whole reason the historic import has two lanes. Sheets from 2009
 * onwards distinguish morning from evening, so their statements carry the canonical
 * `(date, service)` identity; the earlier sheets cannot, and stay date-only.
 *
 * Row order is hymn-book number order, so it says nothing about the order items were
 * sung in. Nothing here may be read as an order of service.
 *
 * Anything the source holds that is not a usable mark is returned as an anomaly rather
 * than dropped. A cell holding only whitespace is the known case: the 2026-08-09
 * workbook turned one such cell into a usage statement, which is why that artifact
 * counts 1,941 date-only rows where the source supports 1,940.
 *
 * @phpstan-type HymnUsageStatement array{
 *     used_on: string,
 *     reported_service: string|null,
 *     source_marker: string,
 *     marker_recognised: bool,
 *     reported_number: string|null,
 *     reported_title: string,
 *     source_workbook: string,
 *     source_sheet: string,
 *     source_row: int,
 *     source_column: string
 * }
 * @phpstan-type HymnSourceAnomaly array{
 *     kind: string,
 *     used_on: string,
 *     source_marker: string,
 *     source_workbook: string,
 *     source_sheet: string,
 *     source_row: int,
 *     source_column: string
 * }
 * @phpstan-type HymnSourceReading array{
 *     statements: list<HymnUsageStatement>,
 *     anomalies: list<HymnSourceAnomaly>
 * }
 */
class HistoricHymnSourceWorkbookReader
{
    /**
     * The earliest serial a date column can hold, distinguishing a real date from the
     * running-total columns headed with a bare year. Excel serial 30000 is 1982-02-13,
     * comfortably after any four-digit year and long before the archive begins.
     */
    private const int EarliestDateSerial = 30000;

    private const string ExcelEpoch = '1899-12-30';

    /** Marks that name a service. Anything else is surfaced rather than assumed. */
    private const array ServiceMarkers = [
        'a' => SermonService::Morning,
        'p' => SermonService::Evening,
    ];

    /** Marks that assert use on a date without naming a service. */
    private const array DateOnlyMarkers = ['●', '•', 'x'];

    /**
     * @param  list<string>|null  $onlySheets  year sheets to read, or null for every year sheet
     * @return HymnSourceReading
     */
    public function read(string $path, ?array $onlySheets = null): array
    {
        $workbook = XlsxReader::open($path);
        $name = basename($path);
        $statements = [];
        $anomalies = [];

        foreach ($this->yearSheets($workbook) as $sheet) {
            if ($onlySheets !== null && ! in_array($sheet, $onlySheets, true)) {
                continue;
            }

            $reading = $this->readSheet($workbook, $sheet, $name);
            $statements = array_merge($statements, $reading['statements']);
            $anomalies = array_merge($anomalies, $reading['anomalies']);
        }

        return ['statements' => $statements, 'anomalies' => $anomalies];
    }

    /**
     * The year sheets this workbook holds, in workbook order.
     *
     * Sheet names are cast back to strings because PHP turns a numeric array key
     * into an int, and every year sheet is named for its year.
     *
     * @return list<string>
     */
    public function yearSheets(XlsxReader $workbook): array
    {
        return array_values(array_filter(
            array_map(strval(...), array_keys($workbook->sheets())),
            fn (string $sheet): bool => preg_match('/^\d{4}$/', $sheet) === 1,
        ));
    }

    /**
     * @return HymnSourceReading
     */
    private function readSheet(XlsxReader $workbook, string $sheet, string $workbookName): array
    {
        $dateColumns = null;
        $numberColumn = null;
        $titleColumn = null;
        $statements = [];
        $anomalies = [];

        foreach ($workbook->rows($sheet) as $rowNumber => $values) {
            if ($dateColumns === null) {
                $header = $this->headerColumns($values);

                if ($header === null) {
                    continue;
                }

                [$numberColumn, $titleColumn] = $header;
                $dateColumns = $this->dateColumns($values, $sheet, $workbookName);

                continue;
            }

            $title = trim($values[$titleColumn] ?? '');

            if ($title === '') {
                continue;
            }

            foreach ($dateColumns as $column => $date) {
                $raw = $values[$column] ?? '';
                $marker = trim($raw);

                if ($raw === '' || $marker === '0') {
                    continue;
                }

                $coordinates = [
                    'used_on' => $date,
                    'source_marker' => $marker,
                    'source_workbook' => $workbookName,
                    'source_sheet' => $sheet,
                    'source_row' => $rowNumber,
                    'source_column' => $column,
                ];

                /**
                 * A cell holding only whitespace marks nothing. It is reported so the
                 * difference against an earlier artifact is explained rather than silent.
                 */
                if ($marker === '') {
                    $anomalies[] = ['kind' => 'blank_marker', ...$coordinates];

                    continue;
                }

                $recognised = $this->isRecognisedMarker($marker);

                if (! $recognised) {
                    $anomalies[] = ['kind' => 'unrecognised_marker', ...$coordinates];
                }

                $statements[] = [
                    ...$coordinates,
                    'reported_service' => $this->service($marker)?->value,
                    'marker_recognised' => $recognised,
                    'reported_number' => trim($values[$numberColumn] ?? '') ?: null,
                    'reported_title' => $title,
                ];
            }
        }

        if ($dateColumns === null) {
            throw new RuntimeException("Sheet '{$sheet}' of '{$workbookName}' has no recognisable header row.");
        }

        return ['statements' => $statements, 'anomalies' => $anomalies];
    }

    /**
     * The `Number` and `Title` columns, if this row is the header row.
     *
     * @param  array<string, string>  $values
     * @return array{0: string, 1: string}|null
     */
    private function headerColumns(array $values): ?array
    {
        $byLabel = [];

        foreach ($values as $column => $value) {
            $byLabel[strtolower(trim($value))] = $column;
        }

        if (! isset($byLabel['number'], $byLabel['title'])) {
            return null;
        }

        return [$byLabel['number'], $byLabel['title']];
    }

    /**
     * Column letter to service date, for every column headed with a date.
     *
     * A year sheet runs slightly past its own year — a "2023" sheet carries the first
     * Sunday of 2024 — so the date is authoritative and the tab name is only a label.
     * That overlap means one date can appear in two workbooks, which is the selection
     * policy's problem to resolve, not this reader's.
     *
     * A date more than a year from the tab is a parse failure rather than an editing
     * habit, and fails here instead of silently attributing a service to a wrong year.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function dateColumns(array $values, string $sheet, string $workbookName): array
    {
        $columns = [];

        foreach ($values as $column => $value) {
            $date = $this->columnDate(trim($value));

            if ($date === null) {
                continue;
            }

            if (abs((int) substr($date, 0, 4) - (int) $sheet) > 1) {
                throw new RuntimeException(
                    "Sheet '{$sheet}' of '{$workbookName}' has column {$column} dated {$date}, more than a year from its tab."
                );
            }

            $columns[$column] = $date;
        }

        if ($columns === []) {
            throw new RuntimeException("Sheet '{$sheet}' of '{$workbookName}' has no service date columns.");
        }

        return $columns;
    }

    /**
     * The service date a column header names, or null if it heads something else.
     *
     * The workbooks are inconsistent by era: the older year sheets store a real Excel
     * date serial, while the 2023-onwards sheets store `dd.mm.yyyy` text. Both are
     * accepted; a bare four-digit year is a running-total column and is not.
     */
    private function columnDate(string $value): ?string
    {
        if (is_numeric($value)) {
            return (int) $value >= self::EarliestDateSerial
                ? Carbon::parse(self::ExcelEpoch)->addDays((int) $value)->toDateString()
                : null;
        }

        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $value, $matches) === 1) {
            return Carbon::createFromDate(
                (int) $matches[3],
                (int) $matches[2],
                (int) $matches[1],
            )->toDateString();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return Carbon::parse($value)->toDateString();
        }

        return null;
    }

    private function service(string $marker): ?SermonService
    {
        return self::ServiceMarkers[strtolower($marker)] ?? null;
    }

    private function isRecognisedMarker(string $marker): bool
    {
        $normalised = strtolower($marker);

        return isset(self::ServiceMarkers[$normalised]) || in_array($normalised, self::DateOnlyMarkers, true);
    }
}
