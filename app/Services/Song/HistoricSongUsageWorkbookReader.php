<?php

declare(strict_types=1);

namespace App\Services\Song;

use Illuminate\Support\Carbon;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

class HistoricSongUsageWorkbookReader
{
    private const string MainNamespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const string RelationshipNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const string PackageRelationshipNamespace = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * @return list<array{
     *     used_on:string,
     *     reported_number:?string,
     *     reported_title:string,
     *     catalog_title:?string,
     *     workbook_match_method:?string,
     *     source_workbook:string,
     *     source_sheet:string,
     *     source_row:int
     * }>
     */
    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Historic song usage workbook is not readable: {$path}");
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open historic song usage workbook: {$path}");
        }

        try {
            $worksheetPath = $this->worksheetPath($zip, 'Ambiguous Usage');
            $sharedStrings = $this->sharedStrings($zip);
        } finally {
            $zip->close();
        }

        return $this->readWorksheet($path, $worksheetPath, $sharedStrings);
    }

    private function worksheetPath(ZipArchive $zip, string $sheetName): string
    {
        $workbook = $this->xmlFromZip($zip, 'xl/workbook.xml');
        $workbook->registerXPathNamespace('x', self::MainNamespace);
        $workbook->registerXPathNamespace('r', self::RelationshipNamespace);

        $relationshipId = null;
        foreach ($workbook->xpath('//x:sheet') ?: [] as $sheet) {
            if ((string) $sheet['name'] !== $sheetName) {
                continue;
            }

            $relationshipId = (string) $sheet->attributes(self::RelationshipNamespace)['id'];
            break;
        }

        if ($relationshipId === null || $relationshipId === '') {
            throw new RuntimeException("Workbook sheet '{$sheetName}' was not found.");
        }

        $relationships = $this->xmlFromZip($zip, 'xl/_rels/workbook.xml.rels');
        $relationships->registerXPathNamespace('p', self::PackageRelationshipNamespace);

        foreach ($relationships->xpath('//p:Relationship') ?: [] as $relationship) {
            if ((string) $relationship['Id'] !== $relationshipId) {
                continue;
            }

            $target = ltrim((string) $relationship['Target'], '/');

            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        throw new RuntimeException("Worksheet relationship '{$relationshipId}' was not found.");
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml)) {
            return [];
        }

        $sharedStrings = simplexml_load_string($xml);
        if (! $sharedStrings instanceof SimpleXMLElement) {
            throw new RuntimeException('The workbook shared strings are invalid XML.');
        }

        $sharedStrings->registerXPathNamespace('x', self::MainNamespace);

        return array_values(array_map(
            fn (SimpleXMLElement $item): string => implode('', array_map('strval', $item->xpath('.//x:t') ?: [])),
            $sharedStrings->xpath('//x:si') ?: [],
        ));
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<array{
     *     used_on:string,
     *     reported_number:?string,
     *     reported_title:string,
     *     catalog_title:?string,
     *     workbook_match_method:?string,
     *     source_workbook:string,
     *     source_sheet:string,
     *     source_row:int
     * }>
     */
    private function readWorksheet(string $workbookPath, string $worksheetPath, array $sharedStrings): array
    {
        $reader = new XMLReader;
        $uri = 'zip://'.$workbookPath.'#'.$worksheetPath;
        if (! $reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Unable to read the Ambiguous Usage worksheet.');
        }

        $headers = [];
        $records = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $row = simplexml_load_string($reader->readOuterXml());
                if (! $row instanceof SimpleXMLElement) {
                    throw new RuntimeException('The Ambiguous Usage worksheet contains invalid row XML.');
                }

                $values = $this->rowValues($row, $sharedStrings);
                if ($headers === []) {
                    $headers = array_flip($values);
                    $this->guardHeaders($headers);

                    continue;
                }

                if ($values === []) {
                    continue;
                }

                $records[] = $this->recordFromValues($values, $headers);
            }
        } finally {
            $reader->close();
        }

        return $records;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return array<int, string>
     */
    private function rowValues(SimpleXMLElement $row, array $sharedStrings): array
    {
        $row->registerXPathNamespace('x', self::MainNamespace);
        $values = [];

        foreach ($row->xpath('./x:c') ?: [] as $cell) {
            $reference = (string) $cell['r'];
            preg_match('/^[A-Z]+/', $reference, $matches);
            $column = $this->columnIndex($matches[0] ?? '');
            $type = (string) $cell['t'];
            $rawValue = (string) ($cell->children(self::MainNamespace)->v ?? '');

            $values[$column] = $type === 's'
                ? ($sharedStrings[(int) $rawValue] ?? '')
                : $rawValue;
        }

        return $values;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /** @param array<string, int> $headers */
    private function guardHeaders(array $headers): void
    {
        $required = [
            'Date',
            'Workbook number',
            'Workbook title',
            'Catalog title',
            'Match method',
            'Source workbook',
            'Source sheet',
            'Source row',
        ];

        $missing = array_values(array_diff($required, array_keys($headers)));
        if ($missing !== []) {
            throw new RuntimeException('Ambiguous Usage is missing required columns: '.implode(', ', $missing));
        }
    }

    /**
     * @param  array<int, string>  $values
     * @param  array<string, int>  $headers
     * @return array{
     *     used_on:string,
     *     reported_number:?string,
     *     reported_title:string,
     *     catalog_title:?string,
     *     workbook_match_method:?string,
     *     source_workbook:string,
     *     source_sheet:string,
     *     source_row:int
     * }
     */
    private function recordFromValues(array $values, array $headers): array
    {
        $value = fn (string $header): string => trim($values[$headers[$header]] ?? '');
        $excelDate = $value('Date');
        $title = $value('Workbook title');

        if (! is_numeric($excelDate) || $title === '') {
            throw new RuntimeException('Ambiguous Usage contains a row without a valid Date or Workbook title.');
        }

        return [
            'used_on' => Carbon::parse('1899-12-30')->addDays((int) $excelDate)->toDateString(),
            'reported_number' => $value('Workbook number') ?: null,
            'reported_title' => $title,
            'catalog_title' => $value('Catalog title') ?: null,
            'workbook_match_method' => $value('Match method') ?: null,
            'source_workbook' => $value('Source workbook'),
            'source_sheet' => $value('Source sheet'),
            'source_row' => (int) $value('Source row'),
        ];
    }

    private function xmlFromZip(ZipArchive $zip, string $path): SimpleXMLElement
    {
        $xml = $zip->getFromName($path);
        if (! is_string($xml)) {
            throw new RuntimeException("Workbook entry '{$path}' was not found.");
        }

        $element = simplexml_load_string($xml);
        if (! $element instanceof SimpleXMLElement) {
            throw new RuntimeException("Workbook entry '{$path}' is invalid XML.");
        }

        return $element;
    }
}
