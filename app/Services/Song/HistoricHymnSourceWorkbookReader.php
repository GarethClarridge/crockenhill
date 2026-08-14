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
     * @return list<HymnUsageStatement>
     */
    public function read(string $path, ?array $onlySheets = null): array
    {
        $workbook = XlsxReader::open($path);
        $name = basename($path);
        $statements = [];

        foreach ($this->yearSheets($workbook) as $sheet) {
            if ($onlySheets !== null && ! in_array($sheet, $onlySheets, true)) {
                continue;
            }

            foreach ($this->readSheet($workbook, $sheet, $name) as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
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
     * @return list<HymnUsageStatement>
     */
    private function readSheet(XlsxReader $workbook, string $sheet, string $workbookName): array
    {
        $dateColumns = null;
        $numberColumn = null;
        $titleColumn = null;
        $statements = [];

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

            $title = $values[$titleColumn] ?? '';

            if ($title === '') {
                continue;
            }

            foreach ($dateColumns as $column => $date) {
                $marker = $values[$column] ?? '';

                if ($marker === '' || $marker === '0') {
                    continue;
                }

                $statements[] = [
                    'used_on' => $date,
                    'reported_service' => $this->service($marker)?->value,
                    'source_marker' => $marker,
                    'marker_recognised' => $this->isRecognisedMarker($marker),
                    'reported_number' => ($values[$numberColumn] ?? '') ?: null,
                    'reported_title' => $title,
                    'source_workbook' => $workbookName,
                    'source_sheet' => $sheet,
                    'source_row' => $rowNumber,
                    'source_column' => $column,
                ];
            }
        }

        if ($dateColumns === null) {
            throw new RuntimeException("Sheet '{$sheet}' of '{$workbookName}' has no recognisable header row.");
        }

        return $statements;
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
            $byLabel[strtolower($value)] = $column;
        }

        if (! isset($byLabel['number'], $byLabel['title'])) {
            return null;
        }

        return [$byLabel['number'], $byLabel['title']];
    }

    /**
     * Column letter to service date, for every column headed with a date serial.
     *
     * A sheet's dates must fall in that sheet's year. A hymn workbook that drifted
     * would otherwise attribute a service to the wrong year silently, and this
     * reconciliation exists precisely to stop silent attribution.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function dateColumns(array $values, string $sheet, string $workbookName): array
    {
        $columns = [];

        foreach ($values as $column => $value) {
            $date = $this->columnDate($value);

            if ($date === null) {
                continue;
            }

            if (! str_starts_with($date, $sheet)) {
                throw new RuntimeException(
                    "Sheet '{$sheet}' of '{$workbookName}' has column {$column} dated {$date}, outside its year."
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
