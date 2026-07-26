<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\InboundEmail;

use App\Actions\InboundEmail\InboundEmailPreviewFactory;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Models\InboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithInboundEmailTestHelpers;

class InboundEmailPreviewFactoryTest extends TestCase
{
    use RefreshDatabase;
    use WithInboundEmailTestHelpers;

    private InboundEmailPreviewFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = app(InboundEmailPreviewFactory::class);
    }

    #[Test]
    public function it_builds_a_complete_preview_from_stored_parsing_metadata(): void
    {
        $email = InboundEmail::factory()->create([
            'body_plain' => "Welcome\nSermon",
            'body_html' => '<p>Welcome</p>',
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-08',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                ],
                warnings: ['Needs review.'],
            ),
        ]);

        $preview = $this->factory->build($email);

        $this->assertSame('2026-06-08', $preview['resolved_date']);
        $this->assertSame('morning', $preview['resolved_service']);
        $this->assertCount(1, $preview['preview_items']);
        $this->assertSame(['Needs review.'], $preview['warnings']);
        $this->assertNotNull($preview['raw_warnings_json']);
        $this->assertNotNull($preview['plain_body']);
        $this->assertTrue($preview['has_plain_body']);
        $this->assertNotNull($preview['sanitized_html']);
        $this->assertTrue($preview['has_html_body']);
        $this->assertNotNull($preview['raw_parsing_json']);
    }

    #[Test]
    public function it_degrades_gracefully_when_metadata_is_null(): void
    {
        $email = InboundEmail::factory()->create([
            'body_plain' => null,
            'body_html' => null,
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => null,
        ]);

        $preview = $this->factory->build($email);

        $this->assertSame([], $preview['preview_items']);
        $this->assertSame([], $preview['warnings']);
        $this->assertNull($preview['raw_warnings_json']);
        $this->assertNull($preview['failure_message']);
        $this->assertNull($preview['resolved_date']);
        $this->assertNull($preview['resolved_service']);
        $this->assertNull($preview['confidence_score']);
        $this->assertFalse($preview['can_approve']);
        $this->assertNull($preview['plain_body']);
        $this->assertFalse($preview['has_plain_body']);
        $this->assertNull($preview['sanitized_html']);
        $this->assertFalse($preview['has_html_body']);
        $this->assertNull($preview['raw_parsing_json']);
        $this->assertNull($preview['reparsed_at']);
    }

    #[Test]
    public function it_sets_can_approve_true_when_all_required_fields_are_present(): void
    {
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-29',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['type' => 'sermon', 'title' => 'A Sermon'],
                ],
            ),
        ]);

        $preview = $this->factory->build($email);

        $this->assertTrue($preview['can_approve']);
    }

    #[Test]
    public function it_sets_can_approve_false_when_items_are_empty(): void
    {
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-29',
                resolvedService: SermonService::Morning->value,
                items: [],
            ),
        ]);

        $preview = $this->factory->build($email);

        $this->assertFalse($preview['can_approve']);
    }

    #[Test]
    public function it_disables_approval_for_a_structurally_invalid_service_plan(): void
    {
        $metadata = $this->processingMetadata(
            resolvedDate: '2026-06-29',
            resolvedService: SermonService::Morning->value,
            items: [['type' => 'sermon', 'title' => 'Merged title']],
        );
        $metadata['parsing']['service_plans'] = [[
            'plan_key' => 'morning:2026-06-29',
            'service' => 'morning',
            'date' => '2026-06-29',
            'confidence' => 0.95,
            'needs_review' => true,
            'should_import' => false,
            'disposition' => 'invalid_extraction',
            'validation_reasons' => ['Item 1 merges separate source lines.'],
            'items' => [['type' => 'sermon', 'title' => 'Merged title']],
        ]];
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $metadata,
        ]);

        $preview = $this->factory->build($email);

        $this->assertFalse($preview['can_approve']);
        $this->assertFalse($preview['service_plans'][0]['can_approve']);
        $this->assertSame(['Item 1 merges separate source lines.'], $preview['service_plans'][0]['validation_reasons']);
    }

    #[Test]
    public function it_sets_can_approve_false_when_resolved_date_is_missing(): void
    {
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '',
                resolvedService: SermonService::Morning->value,
                items: [['type' => 'sermon', 'title' => 'A Sermon']],
            ),
        ]);

        $preview = $this->factory->build($email);

        $this->assertFalse($preview['can_approve']);
    }

    #[Test]
    public function it_sanitizes_html_body_removing_dangerous_tags(): void
    {
        $email = InboundEmail::factory()->create([
            'body_html' => '<p>Safe</p><script>alert("xss")</script><svg><circle/></svg>',
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => null,
        ]);

        $preview = $this->factory->build($email);

        $this->assertNotNull($preview['sanitized_html']);
        $this->assertStringContainsString('<p>Safe</p>', $preview['sanitized_html']);
        $this->assertStringNotContainsString('<script>', $preview['sanitized_html']);
        $this->assertStringNotContainsString('<svg>', $preview['sanitized_html']);
    }

    #[Test]
    public function it_normalises_crlf_line_endings_in_plain_body(): void
    {
        $email = InboundEmail::factory()->create([
            'body_plain' => "Line one\r\nLine two\r\nLine three",
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => null,
        ]);

        $preview = $this->factory->build($email);

        $this->assertSame("Line one\nLine two\nLine three", $preview['plain_body']);
    }

    #[Test]
    public function it_exposes_failure_message_from_failure_metadata(): void
    {
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Failed->value,
            'processing_metadata' => array_replace_recursive(
                $this->processingMetadata(
                    resolvedDate: '2026-06-29',
                    resolvedService: SermonService::Morning->value,
                    items: [],
                ),
                ['failure' => ['message' => 'Parser crashed']],
            ),
        ]);

        $preview = $this->factory->build($email);

        $this->assertSame('Parser crashed', $preview['failure_message']);
    }

    #[Test]
    public function it_exposes_reparsed_at_timestamp_when_present(): void
    {
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => array_replace_recursive(
                $this->processingMetadata(
                    resolvedDate: '2026-06-29',
                    resolvedService: SermonService::Morning->value,
                    items: [],
                ),
                ['reparsed_at' => '2026-03-12T11:30:00+00:00'],
            ),
        ]);

        $preview = $this->factory->build($email);

        $this->assertSame('2026-03-12T11:30:00+00:00', $preview['reparsed_at']);
    }
}
