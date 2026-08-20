<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\InboundEmail;

use App\Actions\InboundEmail\ReparseInboundEmail;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Services\Email\OosSemanticParserCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FixedOosSemanticParserCandidate;
use Tests\TestCase;
use Tests\Traits\WithInboundEmailTestHelpers;

class ReparseInboundEmailTest extends TestCase
{
    use RefreshDatabase;
    use WithInboundEmailTestHelpers;

    #[Test]
    public function it_updates_stored_parsing_metadata_and_returns_null_on_success(): void
    {
        $this->travelTo(Carbon::parse('2026-03-12 11:30:00'));

        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [
                ['type' => 'welcome', 'title' => 'Call to Worship'],
                ['type' => 'sermon', 'title' => 'Sermon'],
            ],
            confidence: 0.97,
            services: [[
                'service' => 'morning',
                'date' => '2026-06-29',
                'items' => [
                    ['type' => 'welcome', 'title' => 'Call to Worship'],
                    ['type' => 'sermon', 'title' => 'Sermon'],
                ],
                'confidence' => 0.97,
            ]],
        ));

        $email = InboundEmail::factory()->create([
            'subject' => 'Order of Service - 2026-06-29 AM',
            'body_plain' => "Call to Worship\nSermon",
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => array_replace_recursive(
                $this->processingMetadata(
                    resolvedDate: '2026-06-22',
                    resolvedService: SermonService::Morning->value,
                    items: [
                        ['position' => 1, 'type' => 'custom', 'title' => 'Old Welcome', 'metadata' => ['email_type' => 'welcome']],
                    ],
                    needsReview: true,
                    shouldImport: false,
                ),
                ['review' => ['notes' => 'Keep this note']],
            ),
        ]);

        $result = app(ReparseInboundEmail::class)->execute($email);

        $this->assertNull($result);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Pending, $email->status);
        $this->assertSame('2026-03-12T11:30:00+00:00', $email->processing_metadata['reparsed_at'] ?? null);
        $this->assertSame('Keep this note', $email->processing_metadata['review']['notes'] ?? null);
        $this->assertSame('2026-06-29', $email->processing_metadata['parsing']['resolved_date'] ?? null);
        $this->assertSame('morning', $email->processing_metadata['parsing']['resolved_service'] ?? null);
        $this->assertSame(['Call to Worship', 'Sermon'], collect($email->processing_metadata['parsing']['items'] ?? [])->pluck('title')->all());
        $this->assertSame(0, ChurchService::count());

        $this->travelBack();
    }

    #[Test]
    public function it_transitions_a_failed_email_back_to_pending(): void
    {
        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'prayer', 'title' => 'Opening Prayer'],
            ],
            confidence: 0.94,
            services: [[
                'service' => 'evening',
                'date' => '2026-07-06',
                'items' => [
                    ['type' => 'welcome', 'title' => 'Welcome'],
                    ['type' => 'prayer', 'title' => 'Opening Prayer'],
                ],
                'confidence' => 0.94,
            ]],
        ));

        $email = InboundEmail::factory()->create([
            'subject' => 'Order of Service - 2026-07-06 PM',
            'body_plain' => "Welcome\nOpening Prayer",
            'status' => InboundEmailStatus::Failed->value,
            'processing_metadata' => array_replace_recursive(
                $this->processingMetadata(
                    resolvedDate: '2026-07-06',
                    resolvedService: SermonService::Morning->value,
                    items: [],
                    needsReview: true,
                    shouldImport: false,
                ),
                ['failure' => ['message' => 'Parser exploded']],
            ),
        ]);

        $result = app(ReparseInboundEmail::class)->execute($email);

        $this->assertNull($result);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Pending, $email->status);
        $this->assertArrayNotHasKey('failure', $email->processing_metadata ?? []);
        $this->assertCount(2, $email->processing_metadata['parsing']['items'] ?? []);
        $this->assertSame(0, ChurchService::count());
    }

    #[Test]
    public function it_returns_an_error_string_and_leaves_email_unchanged_when_parser_fails(): void
    {
        $this->app->bind(OosSemanticParserCandidate::class, fn () => FixedOosSemanticParserCandidate::using(function (OosEmailSourceDocument $source): OosEmailItemExtractionResult {
            throw new \RuntimeException('Parser is broken');
        }));

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-29',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                ],
                needsReview: true,
                shouldImport: false,
            ),
        ]);

        $result = app(ReparseInboundEmail::class)->execute($email);

        $this->assertIsString($result);
        $this->assertStringContainsString('Unable to re-parse', $result);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Pending, $email->status);
        $this->assertNull($email->processing_metadata['reparsed_at'] ?? null);
        $this->assertSame('2026-06-29', $email->processing_metadata['parsing']['resolved_date'] ?? null);
        $this->assertSame('Welcome', $email->processing_metadata['parsing']['items'][0]['title'] ?? null);
    }
}
