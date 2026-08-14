<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * Builds a miniature hymn-database workbook in the crosstab shape the real sources use.
 *
 * Hymns run down the rows and service dates across the columns, with the mark in each
 * cell carrying the service. Strings go through the shared-string table rather than
 * inline, because that is how the real workbooks store them and a shared-string table
 * read back as empty blanks every text cell in the sheet.
 */
class HymnSourceWorkbookFixture
{
    private const string Namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @var list<string> */
    private array $sharedStrings = [];

    /** @var array<string, string> */
    private array $sheets = [];

    /**
     * @param  list<string>  $dateHeaders  raw column headers: an Excel serial or `dd.mm.yyyy`
     * @param  list<array{number: string, title: string, marks: array<int, string>}>  $hymns  marks keyed by date-header index
     */
    public function addSheet(string $name, array $dateHeaders, array $hymns, string $yearTotalColumn = '2011'): self
    {
        $rows = [];
        $header = [
            'A' => $this->text('Number'),
            'B' => $this->text('Category'),
            'C' => $this->text('Title'),
            'D' => $this->text('Total'),
            'E' => $this->text($yearTotalColumn),
        ];

        foreach ($dateHeaders as $index => $value) {
            $header[$this->dateColumn($index)] = is_numeric($value)
                ? $this->number($value)
                : $this->text($value);
        }

        /** A leading row of column totals, exactly as the real sheets carry. */
        $rows[] = $this->row(1, ['E' => $this->number('7')]);
        $rows[] = $this->row(2, $header);

        foreach ($hymns as $offset => $hymn) {
            $cells = [
                'A' => $this->text($hymn['number']),
                'B' => $this->text('Psalms'),
                'C' => $this->text($hymn['title']),
                'D' => $this->number('1'),
                'E' => $this->number('0'),
            ];

            foreach ($hymn['marks'] as $index => $mark) {
                $cells[$this->dateColumn($index)] = $this->text($mark);
            }

            $rows[] = $this->row($offset + 3, $cells);
        }

        $this->sheets[$name] = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="'.self::Namespace.'"><sheetData>'
            .implode('', $rows).'</sheetData></worksheet>';

        return $this;
    }

    public function write(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create fixture workbook at {$path}.");
        }

        $sheetXml = '';
        $relationshipXml = '';
        $index = 0;

        foreach ($this->sheets as $name => $xml) {
            $index++;
            /** A year sheet's name is numeric, so PHP hands it back as an int key. */
            $sheetXml .= '<sheet name="'.htmlspecialchars((string) $name, ENT_XML1).'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
            $relationshipXml .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
            $zip->addFromString("xl/worksheets/sheet{$index}.xml", $xml);
        }

        $zip->addFromString(
            'xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="'.self::Namespace.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            .$sheetXml.'</sheets></workbook>'
        );

        $zip->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationshipXml.'</Relationships>'
        );

        $items = '';
        foreach ($this->sharedStrings as $value) {
            $items .= '<si><t xml:space="preserve">'.htmlspecialchars($value, ENT_XML1).'</t></si>';
        }

        $zip->addFromString(
            'xl/sharedStrings.xml',
            '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="'.self::Namespace.'" count="'.count($this->sharedStrings).'">'
            .$items.'</sst>'
        );

        $zip->close();

        return $path;
    }

    /** @param array<string, string> $cells */
    private function row(int $number, array $cells): string
    {
        $xml = '<row r="'.$number.'">';

        foreach ($cells as $column => $cell) {
            $xml .= str_replace('{ref}', $column.$number, $cell);
        }

        return $xml.'</row>';
    }

    private function text(string $value): string
    {
        $index = array_search($value, $this->sharedStrings, true);

        if ($index === false) {
            $this->sharedStrings[] = $value;
            $index = count($this->sharedStrings) - 1;
        }

        return '<c r="{ref}" t="s"><v>'.$index.'</v></c>';
    }

    private function number(string $value): string
    {
        return '<c r="{ref}"><v>'.$value.'</v></c>';
    }

    private function dateColumn(int $index): string
    {
        return chr(ord('G') + $index);
    }
}
