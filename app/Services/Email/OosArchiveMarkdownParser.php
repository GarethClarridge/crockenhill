<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosArchiveEntry;
use Carbon\CarbonImmutable;
use Throwable;

class OosArchiveMarkdownParser
{
    private const KNOWN_GAPS_HEADING = '## Known gaps and attachment-only items';

    /** @var array<string, string> */
    private const CURATED_CORRECTIONS = [
        'Sunday 5 June 2026|Fwd: order of services for sunday 5th june 2026' => '2026-07-05',
    ];

    /**
     * @return list<OosArchiveEntry>
     */
    public function parse(string $markdown): array
    {
        $archive = explode(self::KNOWN_GAPS_HEADING, $this->normaliseLineEndings($markdown), 2)[0];

        if (preg_match_all('/^###\s+(.+?)\n(.*?)(?=^###\s+|\z)/ms', $archive, $matches, PREG_SET_ORDER) !== false) {
            $entries = [];

            foreach ($matches as $index => $match) {
                $entry = $this->parseEntry($index + 1, trim($match[1]), $match[2]);

                if ($entry instanceof OosArchiveEntry) {
                    $entries[] = $entry;
                }
            }

            return $entries;
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public function knownGaps(string $markdown): array
    {
        $parts = explode(self::KNOWN_GAPS_HEADING, $this->normaliseLineEndings($markdown), 2);

        if (! isset($parts[1])) {
            return [];
        }

        preg_match_all('/^\s*[-*]\s+(.+)$/m', $parts[1], $matches);

        return array_map('trim', $matches[1]);
    }

    private function parseEntry(int $index, string $heading, string $content): ?OosArchiveEntry
    {
        if (preg_match('/^\s*\*\*Source subject:\*\*\s*(.+?)\s*$/mi', $content, $subjectMatch) !== 1) {
            return null;
        }

        $subject = trim($subjectMatch[1]);
        $bodyMarkdown = (string) preg_replace('/^.*?^\s*\*\*Source subject:\*\*\s*.+?\s*$/msi', '', $content, 1);
        $bodyMarkdown = (string) preg_replace('/^---\s*$/m', '', $bodyMarkdown);
        $bodyPlain = $this->plainBody($bodyMarkdown);
        $headingDate = $this->resolveHeadingDate($heading);
        $correctedDate = $this->resolveCorrectedDate($heading, $subject, $headingDate);
        $groundTruthDate = $correctedDate ?? $headingDate;
        [$servicesPresent, $itemLineCounts, $itemLines, $labelQuality] = $this->groundTruthStructure($bodyMarkdown);
        $flags = $this->flags(
            $heading,
            $subject,
            $bodyMarkdown,
            $groundTruthDate,
            $servicesPresent,
            $labelQuality,
            $correctedDate,
        );
        $identity = substr(sha1("{$heading}{$subject}"), 0, 12);
        $inputHash = hash('sha256', $this->normaliseForHash("{$subject}\n{$bodyPlain}"));
        $syntheticReceivedAt = $groundTruthDate === null
            ? null
            : CarbonImmutable::parse($groundTruthDate, 'Europe/London')->subDays(2)->setTime(9, 0);

        return new OosArchiveEntry(
            index: $index,
            heading: $heading,
            subject: $subject,
            bodyPlain: $bodyPlain,
            headingDate: $headingDate,
            correctedDate: $correctedDate,
            groundTruthDate: $groundTruthDate,
            labelQuality: $labelQuality,
            servicesPresent: $servicesPresent,
            itemLineCounts: $itemLineCounts,
            itemLines: $itemLines,
            flags: $flags,
            syntheticMessageId: "<oos-archive-{$identity}@crockenhill.local>",
            inputHash: $inputHash,
            syntheticReceivedAt: $syntheticReceivedAt,
        );
    }

    private function resolveHeadingDate(string $heading): ?string
    {
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})\b/i', $heading, $matches) === 1) {
            return $this->safeDate((int) $matches[3], $this->monthNumber($matches[2]), (int) $matches[1]);
        }

        if (preg_match('/\b(\d{4})\b/', $heading, $yearMatch) !== 1) {
            return null;
        }

        $year = (int) $yearMatch[1];

        if (stripos($heading, 'Christmas Morning') !== false) {
            return "{$year}-12-25";
        }

        if (stripos($heading, 'Christmas weekend') !== false) {
            $christmasEve = CarbonImmutable::parse("{$year}-12-24 12:00:00", 'Europe/London');

            return $christmasEve->subDays($christmasEve->dayOfWeek)->format('Y-m-d');
        }

        $easter = $this->easterSunday($year);

        return match (true) {
            stripos($heading, 'Good Friday') !== false => $easter->subDays(2)->format('Y-m-d'),
            stripos($heading, 'Maundy Thursday') !== false => $easter->subDays(3)->format('Y-m-d'),
            stripos($heading, 'Easter Sunday') !== false => $easter->format('Y-m-d'),
            default => null,
        };
    }

    private function resolveCorrectedDate(string $heading, string $subject, ?string $headingDate): ?string
    {
        $curated = self::CURATED_CORRECTIONS["{$heading}|{$subject}"] ?? null;

        if ($curated !== null) {
            return $curated;
        }

        if ($headingDate === null || preg_match('/\[(?:[^]]*?)(?:likely\s+)?intended\s+(\d{1,2})(?:st|nd|rd|th)?\s+(January|February|March|April|May|June|July|August|September|October|November|December)(?:[^]]*)\]/i', $heading, $matches) !== 1) {
            return null;
        }

        return $this->safeDate((int) substr($headingDate, 0, 4), $this->monthNumber($matches[2]), (int) $matches[1]);
    }

    /**
     * @return array{list<string>, array<string, int>, array<string, list<string>>, string}
     */
    private function groundTruthStructure(string $bodyMarkdown): array
    {
        if (preg_match_all('/^####\s+(.+?)\s*$\n(.*?)(?=^####\s+|\z)/ms', $bodyMarkdown, $sections, PREG_SET_ORDER) === 0) {
            return [[], [], [], 'unverified'];
        }

        $servicesPresent = [];
        $itemLines = [];

        foreach ($sections as $section) {
            $service = $this->classifySection($section[1]);

            if ($service === null) {
                continue;
            }

            if (! in_array($service, $servicesPresent, true)) {
                $servicesPresent[] = $service;
            }

            $itemLines[$service] = array_merge($itemLines[$service] ?? [], $this->sectionItemLines($section[2]));
        }

        $itemLineCounts = [];

        foreach ($itemLines as $service => $lines) {
            $itemLineCounts[$service] = count($lines);
        }

        return [$servicesPresent, $itemLineCounts, $itemLines, 'full'];
    }

    private function classifySection(string $heading): ?string
    {
        $normalised = strtolower(trim($heading));

        if (str_contains($normalised, 'notice') || str_contains($normalised, 'question')) {
            return null;
        }

        if (str_contains($normalised, 'morning')) {
            return 'morning';
        }

        if (str_contains($normalised, 'evening') || str_contains($normalised, 'carol')) {
            return 'evening';
        }

        return str_contains($normalised, 'service') || str_contains($normalised, 'order')
            ? 'other'
            : null;
    }

    /**
     * @return list<string>
     */
    private function sectionItemLines(string $section): array
    {
        $lines = [];

        foreach (explode("\n", $section) as $line) {
            $line = trim((string) preg_replace('/^[-*]\s+/', '', $line));
            $line = trim(str_replace('**', '', $line));

            if ($line !== '' && $line !== '---') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $servicesPresent
     * @return list<string>
     */
    private function flags(
        string $heading,
        string $subject,
        string $bodyMarkdown,
        ?string $groundTruthDate,
        array $servicesPresent,
        string $labelQuality,
        ?string $correctedDate,
    ): array {
        $flags = [];

        if (str_contains($heading, '[')) {
            $flags[] = 'date_discrepancy';
        }

        if (($correctedDate !== null) && isset(self::CURATED_CORRECTIONS["{$heading}|{$subject}"])) {
            $flags[] = 'source_date_discrepancy';
        }

        if (str_starts_with(strtolower($heading), 'sunday ') && $groundTruthDate !== null && ! CarbonImmutable::parse($groundTruthDate)->isSunday()) {
            $flags[] = 'weekday_mismatch';
        }

        if (preg_match('/\b(revised|original)\b/i', $heading) === 1) {
            $flags[] = 'revised_original_pair';
        }

        if ($servicesPresent === ['morning'] || ($labelQuality === 'unverified' && preg_match('/\b(?:AM|morning)\b/i', $heading) === 1)) {
            $flags[] = 'morning_only';
        }

        if ($groundTruthDate !== null && $this->containsDifferentSectionDate($bodyMarkdown, $groundTruthDate)) {
            $flags[] = 'multi_date';
        }

        return array_values(array_unique($flags));
    }

    private function containsDifferentSectionDate(string $bodyMarkdown, string $groundTruthDate): bool
    {
        if (preg_match_all('/^####\s+(.+?)\s*$/m', $bodyMarkdown, $headings) === 0) {
            return false;
        }

        foreach ($headings[1] as $heading) {
            if (stripos($heading, 'Christmas morning') !== false && ! str_ends_with($groundTruthDate, '-12-25')) {
                return true;
            }

            $sectionDate = $this->resolveHeadingDate($heading);
            if ($sectionDate !== null && $sectionDate !== $groundTruthDate) {
                return true;
            }
        }

        return false;
    }

    private function plainBody(string $bodyMarkdown): string
    {
        $body = (string) preg_replace('/^####\s+(.+?)\s*$/m', '$1', $bodyMarkdown);
        $body = str_replace('**', '', $body);
        $body = (string) preg_replace('/^---\s*$/m', '', $body);
        $body = (string) preg_replace("/\n{3,}/", "\n\n", $body);

        return trim($body);
    }

    private function normaliseForHash(string $value): string
    {
        $value = $this->normaliseLineEndings($value);
        $value = (string) preg_replace('/[ \t]+/', ' ', $value);
        $value = (string) preg_replace("/\n{3,}/", "\n\n", $value);

        return trim($value);
    }

    private function normaliseLineEndings(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    private function safeDate(int $year, int $month, int $day): ?string
    {
        try {
            $date = CarbonImmutable::createSafe($year, $month, $day, 12, 0, 0, 'Europe/London');

            return $date?->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function easterSunday(int $year): CarbonImmutable
    {
        return CarbonImmutable::parse("{$year}-03-21 12:00:00", 'Europe/London')->addDays(easter_days($year));
    }

    private function monthNumber(string $month): int
    {
        return match (strtolower($month)) {
            'january' => 1,
            'february' => 2,
            'march' => 3,
            'april' => 4,
            'may' => 5,
            'june' => 6,
            'july' => 7,
            'august' => 8,
            'september' => 9,
            'october' => 10,
            'november' => 11,
            default => 12,
        };
    }
}
