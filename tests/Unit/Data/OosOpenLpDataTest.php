<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailParseResult;
use App\Data\OpenLpImportResult;
use App\Data\OpenLpParseResult;
use App\Enums\SermonService;
use App\Models\ChurchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosOpenLpDataTest extends TestCase
{
    use RefreshDatabase;

    // ── OosEmailItemExtractionResult ──────────────────────────────────────────

    #[Test]
    public function extraction_result_stores_all_properties(): void
    {
        $result = new OosEmailItemExtractionResult(
            items: [['type' => 'song', 'title' => 'Amazing Grace']],
            confidence: 0.92,
            notes: ['Matched via fuzzy search'],
        );

        $this->assertSame([['type' => 'song', 'title' => 'Amazing Grace']], $result->items);
        $this->assertSame(0.92, $result->confidence);
        $this->assertSame(['Matched via fuzzy search'], $result->notes);
    }

    #[Test]
    public function extraction_result_notes_default_to_empty_array(): void
    {
        $result = new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.5,
        );

        $this->assertSame([], $result->notes);
    }

    // ── OosEmailParseResult ───────────────────────────────────────────────────

    #[Test]
    public function oos_parse_result_stores_all_properties(): void
    {
        $result = new OosEmailParseResult(
            date: '2024-03-10',
            service: SermonService::MORNING,
            items: [['position' => 1, 'type' => 'song', 'title' => 'How Great Thou Art', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null]],
            confidenceScore: 0.88,
            needsReview: false,
            shouldImport: true,
            importMetadata: ['source' => 'email'],
        );

        $this->assertSame('2024-03-10', $result->date);
        $this->assertSame(SermonService::MORNING, $result->service);
        $this->assertCount(1, $result->items);
        $this->assertSame(0.88, $result->confidenceScore);
        $this->assertFalse($result->needsReview);
        $this->assertTrue($result->shouldImport);
        $this->assertSame(['source' => 'email'], $result->importMetadata);
    }

    #[Test]
    public function oos_parse_result_supports_null_date_and_service(): void
    {
        $result = new OosEmailParseResult(
            date: null,
            service: null,
            items: [],
            confidenceScore: 0.1,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
        );

        $this->assertNull($result->date);
        $this->assertNull($result->service);
    }

    // ── OpenLpParseResult ─────────────────────────────────────────────────────

    #[Test]
    public function openlp_parse_result_stores_all_properties(): void
    {
        $result = new OpenLpParseResult(
            date: '2024-06-02',
            service: SermonService::EVENING,
            items: [['position' => 1, 'type' => 'sermon', 'title' => 'The Gospel', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null]],
            needsReview: false,
            importMetadata: ['file' => 'service.osz'],
        );

        $this->assertSame('2024-06-02', $result->date);
        $this->assertSame(SermonService::EVENING, $result->service);
        $this->assertCount(1, $result->items);
        $this->assertFalse($result->needsReview);
        $this->assertSame(['file' => 'service.osz'], $result->importMetadata);
    }

    // ── OpenLpImportResult ────────────────────────────────────────────────────

    #[Test]
    public function openlp_import_result_stores_all_properties(): void
    {
        $churchService = ChurchService::factory()->create();
        $parseResult = new OpenLpParseResult(
            date: '2024-06-02',
            service: SermonService::MORNING,
            items: [],
            needsReview: false,
            importMetadata: [],
        );

        $result = new OpenLpImportResult(
            churchService: $churchService,
            parseResult: $parseResult,
            wasCreated: true,
            syncResult: ['conflicts' => []],
            linkResult: [
                'dry_run' => false,
                'processed' => 5,
                'matched' => 4,
                'unmatched' => 1,
                'updated' => 3,
                'unchanged' => 1,
                'cleared' => 0,
            ],
        );

        $this->assertSame($churchService->id, $result->churchService->id);
        $this->assertSame($parseResult, $result->parseResult);
        $this->assertTrue($result->wasCreated);
        $this->assertSame(['conflicts' => []], $result->syncResult);
        $this->assertSame(5, $result->linkResult['processed']);
    }
}
