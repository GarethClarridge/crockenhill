<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;

readonly class OosEmailSourceDocument
{
    public const int PortableFormatVersion = 2;

    /**
     * @param  array<int, string>  $lines
     * @param  array<int, array{line_id:int,physical_position:int,text:string,exact_text:string,preceding_blank:bool,following_blank:bool,forwarded_depth:int,marker:?string}>  $semanticLines
     */
    private function __construct(
        private array $lines,
        private array $semanticLines,
        public ?string $subject = null,
        public ?string $receivedDate = null,
    ) {}

    public static function fromBody(string $body): self
    {
        return self::fromContext(null, $body, null);
    }

    public static function fromContext(?string $subject, string $body, ?string $receivedDate): self
    {
        $normalised = preg_replace("/\r\n?/", "\n", $body) ?? $body;
        $physicalLines = explode("\n", $normalised);
        $lines = [];
        $semanticLines = [];

        foreach ($physicalLines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed !== '') {
                $lineId = $index + 1;
                $lines[$lineId] = $trimmed;
                $semanticLines[$lineId] = [
                    'line_id' => $lineId,
                    'physical_position' => $lineId,
                    'text' => $trimmed,
                    'exact_text' => $line,
                    'preceding_blank' => $index > 0 && trim($physicalLines[$index - 1]) === '',
                    'following_blank' => isset($physicalLines[$index + 1]) && trim($physicalLines[$index + 1]) === '',
                    'forwarded_depth' => self::forwardedDepth($line),
                    'marker' => self::marker($trimmed),
                ];
            }
        }

        return new self($lines, $semanticLines, $subject, $receivedDate);
    }

    /**
     * @param  list<array{line_id:int,text:string}>  $records
     */
    public static function fromLineRecords(array $records): self
    {
        $lines = [];
        $semanticLines = [];

        foreach ($records as $record) {
            $lines[$record['line_id']] = $record['text'];
            $semanticLines[$record['line_id']] = [
                'line_id' => $record['line_id'],
                'physical_position' => $record['line_id'],
                'text' => $record['text'],
                'exact_text' => $record['text'],
                'preceding_blank' => false,
                'following_blank' => false,
                'forwarded_depth' => self::forwardedDepth($record['text']),
                'marker' => self::marker($record['text']),
            ];
        }

        return new self($lines, $semanticLines);
    }

    /**
     * @return list<array{line_id:int,text:string}>
     */
    public function lineRecords(): array
    {
        $records = [];

        foreach ($this->lines as $lineId => $text) {
            $records[] = ['line_id' => $lineId, 'text' => $text];
        }

        return $records;
    }

    /**
     * @return list<int>
     */
    public function lineIds(): array
    {
        return array_keys($this->lines);
    }

    public function hasLine(int $lineId): bool
    {
        return array_key_exists($lineId, $this->lines);
    }

    public function line(int $lineId): ?string
    {
        return $this->lines[$lineId] ?? null;
    }

    public function exactLine(int $lineId): ?string
    {
        return $this->semanticLines[$lineId]['exact_text'] ?? null;
    }

    /**
     * @return list<array{line_id:int,physical_position:int,text:string,exact_text:string,preceding_blank:bool,following_blank:bool,forwarded_depth:int,marker:?string}>
     */
    public function semanticLineRecords(): array
    {
        return array_values($this->semanticLines);
    }

    public function inputHash(): string
    {
        return CanonicalJson::hash([
            'format_version' => self::PortableFormatVersion,
            'subject' => $this->subject,
            'received_date' => $this->receivedDate,
            'lines' => $this->semanticLineRecords(),
        ]);
    }

    /** @return list<array{date:string,weekday:string}> */
    public function calendarContext(int $days = 14): array
    {
        if ($this->receivedDate === null) {
            return [];
        }

        $received = CarbonImmutable::createFromFormat('Y-m-d', $this->receivedDate);

        if (! $received instanceof CarbonImmutable || $received->toDateString() !== $this->receivedDate) {
            return [];
        }

        $calendar = [];

        for ($offset = 0; $offset <= $days; $offset++) {
            $date = $received->addDays($offset);
            $calendar[] = [
                'date' => $date->toDateString(),
                'weekday' => $date->englishDayOfWeek,
            ];
        }

        return $calendar;
    }

    /**
     * @param  list<int>  $lineIds
     */
    public function textFor(array $lineIds): ?string
    {
        $lines = [];

        foreach ($lineIds as $lineId) {
            $line = $this->line($lineId);

            if ($line === null) {
                return null;
            }

            $lines[] = $line;
        }

        return $lines === [] ? null : implode(' ', $lines);
    }

    /**
     * @param  list<int>  $lineIds
     */
    public function arePhysicallyConsecutive(array $lineIds): bool
    {
        if ($lineIds === []) {
            return false;
        }

        $previous = null;

        foreach ($lineIds as $lineId) {
            if ($previous !== null && $lineId !== $previous + 1) {
                return false;
            }

            $previous = $lineId;
        }

        return true;
    }

    public function promptBody(): string
    {
        return implode("\n", array_map(
            fn (int $lineId, string $line): string => sprintf('[L%03d] %s', $lineId, $line),
            array_keys($this->lines),
            array_values($this->lines),
        ));
    }

    public function semanticPromptBody(): string
    {
        return implode("\n", array_map(
            static fn (array $line): string => sprintf(
                '[L%03d blank_before=%s blank_after=%s forward_depth=%d marker=%s] %s',
                $line['line_id'],
                $line['preceding_blank'] ? 'yes' : 'no',
                $line['following_blank'] ? 'yes' : 'no',
                $line['forwarded_depth'],
                $line['marker'] ?? 'none',
                $line['exact_text'],
            ),
            $this->semanticLineRecords(),
        ));
    }

    private static function forwardedDepth(string $line): int
    {
        preg_match('/^\s*(>+)/', $line, $matches);

        return isset($matches[1]) ? mb_strlen($matches[1]) : 0;
    }

    private static function marker(string $line): ?string
    {
        if (preg_match('/^(?:from|sent|to|cc|subject|date):/iu', $line) === 1) {
            return 'forwarded_header';
        }

        if (preg_match('/^(?:[-_=*]\s*){3,}$/u', $line) === 1
            || preg_match('/^(?:begin\s+)?forwarded message:?$/iu', $line) === 1) {
            return 'separator';
        }

        return null;
    }
}
