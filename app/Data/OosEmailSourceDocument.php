<?php

declare(strict_types=1);

namespace App\Data;

readonly class OosEmailSourceDocument
{
    /**
     * @param  array<int, string>  $lines
     */
    private function __construct(
        private array $lines,
    ) {}

    public static function fromBody(string $body): self
    {
        $normalised = preg_replace("/\r\n?/", "\n", $body) ?? $body;
        $lines = [];

        foreach (explode("\n", $normalised) as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed !== '') {
                $lines[$index + 1] = $trimmed;
            }
        }

        return new self($lines);
    }

    /**
     * @param  list<array{line_id:int,text:string}>  $records
     */
    public static function fromLineRecords(array $records): self
    {
        $lines = [];

        foreach ($records as $record) {
            $lines[$record['line_id']] = $record['text'];
        }

        return new self($lines);
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
}
