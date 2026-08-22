<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\OosEmailSourceDocument;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosEmailSourceDocumentTest extends TestCase
{
    #[Test]
    public function it_preserves_physical_ids_exact_text_boundaries_and_forwarding_metadata(): void
    {
        $document = OosEmailSourceDocument::fromContext(
            'Order for Sunday',
            "  Morning Service  \n\n> From: old@example.test\n---\nSong",
            '2026-08-19',
        );

        $this->assertSame([1, 3, 4, 5], $document->lineIds());
        $this->assertSame('Morning Service', $document->line(1));
        $this->assertSame('  Morning Service  ', $document->exactLine(1));
        $this->assertTrue($document->semanticLineRecords()[0]['following_blank']);
        $this->assertTrue($document->semanticLineRecords()[1]['preceding_blank']);
        $this->assertSame(1, $document->semanticLineRecords()[1]['forwarded_depth']);
        $this->assertSame('separator', $document->semanticLineRecords()[2]['marker']);
        $this->assertCount(15, $document->calendarContext());
    }

    #[Test]
    public function its_hash_binds_context_and_every_output_affecting_source_field(): void
    {
        $first = OosEmailSourceDocument::fromContext('First', "Welcome\nSong", '2026-08-19');
        $same = OosEmailSourceDocument::fromContext('First', "Welcome\nSong", '2026-08-19');
        $differentSubject = OosEmailSourceDocument::fromContext('Second', "Welcome\nSong", '2026-08-19');
        $differentWhitespace = OosEmailSourceDocument::fromContext('First', "Welcome\n Song", '2026-08-19');

        $this->assertSame($first->inputHash(), $same->inputHash());
        $this->assertNotSame($first->inputHash(), $differentSubject->inputHash());
        $this->assertNotSame($first->inputHash(), $differentWhitespace->inputHash());
        $this->assertSame(
            [['line_id' => 1, 'text' => 'Welcome'], ['line_id' => 2, 'text' => 'Song']],
            $differentWhitespace->lineRecords(),
        );
    }

    /**
     * The 2026-08-22 structural-holds defect: {@see OosArchiveAssertionBundle} built its exported
     * `source_document` from a raw body while the live parser validated against a body with runs
     * of blank lines already collapsed to one. A source with two-or-more consecutive blank lines
     * therefore numbered every line after the run differently under each path, so a bundle's
     * `service_evidence_line_ids` silently pointed at the wrong physical line.
     */
    #[Test]
    public function it_collapses_runs_of_blank_lines_so_every_caller_numbers_lines_identically(): void
    {
        $body = "Morning:\n\nHymn 1\n\n\nEvening:\n\nHymn 2";

        $document = OosEmailSourceDocument::fromContext('Order for Sunday', $body, '2026-08-19');

        $this->assertSame([1, 3, 5, 7], $document->lineIds());
        $this->assertSame('Evening:', $document->line(5));
    }
}
