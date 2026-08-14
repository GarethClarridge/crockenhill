<?php

declare(strict_types=1);

namespace App\Support\Xlsx;

use Generator;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

/**
 * Streaming reader for the parts of the xlsx package this application needs.
 *
 * The historic hymn workbooks are crosstabs of roughly 1,400 rows by 70 columns per
 * year sheet, so rows are yielded one at a time rather than materialised: a
 * reconciliation reads four workbooks in one pass and never holds more than the
 * shared-string table in memory.
 *
 * Cells are keyed by column letter rather than by header name because a crosstab's
 * columns are data — a date — not labels.
 */
class XlsxReader
{
    private const string MainNamespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const string RelationshipNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var list<string>|null */
    private ?array $sharedStrings = null;

    /** @var array<string, string>|null */
    private ?array $sheets = null;

    private function __construct(private readonly string $path) {}

    public static function open(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Workbook is not readable: {$path}");
        }

        return new self($path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function sha256(): string
    {
        $digest = hash_file('sha256', $this->path);

        if ($digest === false) {
            throw new RuntimeException("Unable to digest workbook: {$this->path}");
        }

        return $digest;
    }

    /**
     * Sheet name to its worksheet path inside the package, in workbook order.
     *
     * @return array<string, string>
     */
    public function sheets(): array
    {
        if ($this->sheets !== null) {
            return $this->sheets;
        }

        $zip = $this->openZip();

        try {
            $workbook = $this->xmlFromZip($zip, 'xl/workbook.xml');
            $relationships = $this->xmlFromZip($zip, 'xl/_rels/workbook.xml.rels');
        } finally {
            $zip->close();
        }

        $targets = [];
        foreach ($relationships->children() as $relationship) {
            $targets[(string) $relationship['Id']] = (string) $relationship['Target'];
        }

        $workbook->registerXPathNamespace('x', self::MainNamespace);
        $sheets = [];

        foreach ($workbook->xpath('//x:sheet') ?: [] as $sheet) {
            $relationshipId = (string) $sheet->attributes(self::RelationshipNamespace)['id'];
            $target = $targets[$relationshipId] ?? null;

            if ($target === null) {
                throw new RuntimeException("Worksheet relationship '{$relationshipId}' was not found.");
            }

            $sheets[(string) $sheet['name']] = $this->normalizeWorksheetPath($target);
        }

        return $this->sheets = $sheets;
    }

    /**
     * Every row of one sheet, in document order, as column letter to trimmed value.
     *
     * Empty cells are omitted rather than returned blank, so a caller iterating a
     * sparse crosstab visits only the marks that exist.
     *
     * @return Generator<int, array<string, string>>
     */
    public function rows(string $sheetName): Generator
    {
        $sheets = $this->sheets();

        if (! array_key_exists($sheetName, $sheets)) {
            throw new RuntimeException("Workbook sheet '{$sheetName}' was not found.");
        }

        $sharedStrings = $this->sharedStrings();
        $reader = new XMLReader;
        $uri = 'zip://'.$this->path.'#'.$sheets[$sheetName];

        if (! $reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException("Unable to read worksheet '{$sheetName}'.");
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $row = simplexml_load_string($reader->readOuterXml());

                if (! $row instanceof SimpleXMLElement) {
                    throw new RuntimeException("Worksheet '{$sheetName}' contains invalid row XML.");
                }

                yield (int) $row['r'] => $this->rowValues($row, $sharedStrings);
            }
        } finally {
            $reader->close();
        }
    }

    /** @return list<string> */
    private function sharedStrings(): array
    {
        if ($this->sharedStrings !== null) {
            return $this->sharedStrings;
        }

        $zip = $this->openZip();

        try {
            $xml = $zip->getFromName('xl/sharedStrings.xml');
        } finally {
            $zip->close();
        }

        if (! is_string($xml)) {
            return $this->sharedStrings = [];
        }

        $sharedStrings = simplexml_load_string($xml);

        if (! $sharedStrings instanceof SimpleXMLElement) {
            throw new RuntimeException('The workbook shared strings are invalid XML.');
        }

        $sharedStrings->registerXPathNamespace('x', self::MainNamespace);

        /**
         * Each `si` needs the prefix registered on itself: an xpath prefix registered on
         * the document root does not reach an element returned from it, and a shared-string
         * table that silently reads back as empty strings turns every text cell blank.
         */
        return $this->sharedStrings = array_values(array_map(
            function (SimpleXMLElement $item): string {
                $item->registerXPathNamespace('x', self::MainNamespace);

                return implode('', array_map('strval', $item->xpath('.//x:t') ?: []));
            },
            $sharedStrings->xpath('//x:si') ?: [],
        ));
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return array<string, string>
     */
    private function rowValues(SimpleXMLElement $row, array $sharedStrings): array
    {
        $row->registerXPathNamespace('x', self::MainNamespace);
        $values = [];

        foreach ($row->xpath('./x:c') ?: [] as $cell) {
            preg_match('/^[A-Z]+/', (string) $cell['r'], $matches);
            $column = $matches[0] ?? '';

            if ($column === '') {
                continue;
            }

            $type = (string) $cell['t'];

            /**
             * An inline string keeps its text in `<is>` rather than `<v>`, and may split
             * it across formatting runs. The prefix has to be registered on the cell
             * itself: registering it on the row does not reach a child element's xpath.
             */
            if ($type === 'inlineStr') {
                $cell->registerXPathNamespace('x', self::MainNamespace);
                $value = implode('', array_map('strval', $cell->xpath('.//x:t') ?: []));
            } else {
                $value = (string) ($cell->children(self::MainNamespace)->v ?? '');
            }

            if ($type === 's' && $value !== '') {
                $value = $sharedStrings[(int) $value] ?? '';
            }

            $value = trim($value);

            if ($value !== '') {
                $values[$column] = $value;
            }
        }

        return $values;
    }

    private function normalizeWorksheetPath(string $target): string
    {
        $target = ltrim($target, '/');

        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    private function openZip(): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($this->path) !== true) {
            throw new RuntimeException("Unable to open workbook: {$this->path}");
        }

        return $zip;
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
