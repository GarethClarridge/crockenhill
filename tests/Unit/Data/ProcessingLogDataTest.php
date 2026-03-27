<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\ProcessingLogCollection;
use App\Data\ProcessingLogEntry;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingLogDataTest extends TestCase
{
    private function makeEntry(string $step = 'transcription', string $level = 'info'): ProcessingLogEntry
    {
        return new ProcessingLogEntry(
            step: $step,
            level: $level,
            message: 'Processing step completed',
            timestamp: Carbon::parse('2024-03-10T10:00:00Z'),
        );
    }

    // ── ProcessingLogEntry ────────────────────────────────────────────────────

    #[Test]
    public function entry_stores_all_required_properties(): void
    {
        $timestamp = Carbon::parse('2024-03-10T10:00:00Z');

        $entry = new ProcessingLogEntry(
            step: 'transcription',
            level: 'info',
            message: 'Transcription completed',
            timestamp: $timestamp,
            metrics: ['duration' => 2.5],
            errorMessage: null,
            context: ['file' => 'sermon.mp3'],
            executionTime: 1.23,
            memoryUsage: 52428800,
        );

        $this->assertSame('transcription', $entry->step);
        $this->assertSame('info', $entry->level);
        $this->assertSame('Transcription completed', $entry->message);
        $this->assertSame($timestamp, $entry->timestamp);
        $this->assertSame(['duration' => 2.5], $entry->metrics);
        $this->assertNull($entry->errorMessage);
        $this->assertSame(['file' => 'sermon.mp3'], $entry->context);
        $this->assertSame(1.23, $entry->executionTime);
        $this->assertSame(52428800, $entry->memoryUsage);
    }

    #[Test]
    public function entry_to_array_includes_all_keys(): void
    {
        $arr = $this->makeEntry()->toArray();

        foreach (['step', 'level', 'message', 'timestamp', 'metrics', 'error_message', 'context', 'execution_time', 'memory_usage'] as $key) {
            $this->assertArrayHasKey($key, $arr, "Missing key: {$key}");
        }
    }

    #[Test]
    public function entry_to_array_formats_timestamp_as_iso_string(): void
    {
        $entry = $this->makeEntry();
        $arr = $entry->toArray();

        $this->assertIsString($arr['timestamp']);
        $this->assertStringContainsString('2024-03-10', $arr['timestamp']);
    }

    #[Test]
    public function entry_optional_fields_default_to_null(): void
    {
        $entry = $this->makeEntry();

        $this->assertNull($entry->metrics);
        $this->assertNull($entry->errorMessage);
        $this->assertNull($entry->context);
        $this->assertNull($entry->executionTime);
        $this->assertNull($entry->memoryUsage);
    }

    // ── ProcessingLogCollection ───────────────────────────────────────────────

    #[Test]
    public function collection_stores_entries_and_total_count(): void
    {
        $entries = collect([$this->makeEntry(), $this->makeEntry('analysis')]);
        $collection = new ProcessingLogCollection(entries: $entries, totalCount: 10);

        $this->assertSame(2, $collection->count());
        $this->assertSame(10, $collection->totalCount);
        $this->assertFalse($collection->isEmpty());
    }

    #[Test]
    public function collection_empty_factory_returns_empty_collection(): void
    {
        $collection = ProcessingLogCollection::empty();

        $this->assertTrue($collection->isEmpty());
        $this->assertSame(0, $collection->count());
        $this->assertSame(0, $collection->totalCount);
        $this->assertNull($collection->nextCursor);
        $this->assertNull($collection->summary);
    }

    #[Test]
    public function collection_to_array_serialises_entries(): void
    {
        $entries = collect([$this->makeEntry()]);
        $collection = new ProcessingLogCollection(
            entries: $entries,
            totalCount: 1,
            nextCursor: 'cursor-abc',
            summary: ['errors' => 0],
        );

        $arr = $collection->toArray();

        $this->assertCount(1, $arr['entries']);
        $this->assertSame(1, $arr['total_count']);
        $this->assertSame('cursor-abc', $arr['next_cursor']);
        $this->assertSame(['errors' => 0], $arr['summary']);
        $this->assertArrayHasKey('step', $arr['entries'][0]);
    }
}
