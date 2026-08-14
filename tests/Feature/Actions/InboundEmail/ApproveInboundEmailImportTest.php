<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\InboundEmail;

use App\Actions\InboundEmail\ApproveInboundEmailImport;
use App\Data\OosEmailImportResult;
use App\Data\OosEmailItemExtractionResult;
use App\Enums\InboundEmailStatus;
use App\Enums\OosEmailParseDisposition;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\User;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosEmailParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithInboundEmailTestHelpers;

class ApproveInboundEmailImportTest extends TestCase
{
    use RefreshDatabase;
    use WithInboundEmailTestHelpers;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function it_imports_and_returns_a_church_service_on_success(): void
    {
        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'sermon', 'title' => 'Sermon'],
            ],
            confidence: 0.90,
            services: [[
                'service' => 'morning',
                'date' => '2026-06-22',
                'items' => [
                    ['type' => 'welcome', 'title' => 'Welcome'],
                    ['type' => 'sermon', 'title' => 'Sermon'],
                ],
                'confidence' => 0.90,
            ]],
        ));

        $email = InboundEmail::factory()->create([
            'subject' => 'Order of Service - 2026-06-22 AM',
            'body_plain' => "Welcome\nSermon",
            'status' => InboundEmailStatus::Pending->value,
            'received_at' => '2026-06-20 09:00:00',
        ]);

        $result = app(ApproveInboundEmailImport::class)->execute($email, $this->admin->id);

        $this->assertInstanceOf(OosEmailImportResult::class, $result);
        $service = $result->firstCreatedService();
        $this->assertInstanceOf(ChurchService::class, $service);
        $this->assertSame('email', $service->source);
        $this->assertFalse($service->needs_review);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Processed, $email->status);
        $this->assertSame('direct_approve', $email->processing_metadata['review']['mode'] ?? null);
        $this->assertSame($this->admin->id, $email->processing_metadata['review']['approved_by_user_id'] ?? null);
    }

    /**
     * F65's whole point. A plan held only because the model failed to account for a sign-off line
     * must remain something a human can accept: before the split, any validator reason made the
     * plan an invalid extraction, `importablePlans()` returned nothing, and approval answered
     * "this email still needs manual editing" with no editing that could ever clear it. The
     * 2026-08-14 review measured 148 approved identities stuck behind exactly that.
     */
    #[Test]
    public function an_operator_can_approve_a_plan_held_only_for_unaccounted_bookkeeping(): void
    {
        $body = "Welcome\nSermon\nMany thanks,";
        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.95,
            services: [[
                'service' => 'morning',
                'date' => '2026-06-22',
                'service_evidence_line_ids' => [],
                'items' => [
                    ['type' => 'welcome', 'title' => 'Welcome', 'source_line_ids' => [1], 'continuation' => false],
                    ['type' => 'sermon', 'title' => 'Sermon', 'source_line_ids' => [2], 'continuation' => false],
                ],
                'confidence' => 0.95,
            ]],
            serviceCount: 1,
            // Line 3 is left unaccounted for: not an item, not evidence, not ignored.
            ignoredLines: [],
            provenanceComplete: true,
        ));

        $email = InboundEmail::factory()->create([
            'subject' => 'Order of Service - 2026-06-22 AM',
            'body_plain' => $body,
            'status' => InboundEmailStatus::Pending->value,
            'received_at' => '2026-06-20 09:00:00',
        ]);

        $parseResult = app(OosEmailParserService::class)->parse($email);

        // Held, not rejected, and specifically not something the pipeline would import by itself.
        $this->assertSame(OosEmailParseDisposition::ReviewRequired, $parseResult->disposition);
        $this->assertFalse($parseResult->servicePlans[0]->isAutoImportable());
        $this->assertNotSame([], $parseResult->validationReasons);

        $result = app(ApproveInboundEmailImport::class)->execute($email, $this->admin->id);

        $this->assertInstanceOf(OosEmailImportResult::class, $result);
        $this->assertInstanceOf(ChurchService::class, $result->firstCreatedService());
    }

    #[Test]
    public function it_uses_stored_parse_result_before_reparsing(): void
    {
        $this->bindFailingExtractor();

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-29',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                    ['position' => 2, 'type' => 'custom', 'title' => 'Sermon', 'metadata' => ['email_type' => 'sermon']],
                ],
            ),
        ]);

        $result = app(ApproveInboundEmailImport::class)->execute($email, $this->admin->id);

        $this->assertInstanceOf(OosEmailImportResult::class, $result);
        $service = $result->firstCreatedService();
        $this->assertInstanceOf(ChurchService::class, $service);
        $this->assertSame(['Welcome', 'Sermon'], $service->items()->orderBy('position')->pluck('title')->all());
    }

    #[Test]
    public function it_returns_an_error_string_when_parse_result_is_missing_required_fields(): void
    {
        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.10,
        ));

        $email = InboundEmail::factory()->create([
            'subject' => 'Unparseable email',
            'body_plain' => 'Nothing useful here',
            'status' => InboundEmailStatus::Pending->value,
        ]);

        $result = app(ApproveInboundEmailImport::class)->execute($email, $this->admin->id);

        $this->assertIsString($result);
        $this->assertStringContainsString('manual editing', $result);
        $this->assertSame(0, ChurchService::count());
    }

    #[Test]
    public function it_returns_an_error_string_when_import_throws(): void
    {
        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [
                ['type' => 'welcome', 'title' => 'Welcome'],
            ],
            confidence: 0.90,
            services: [[
                'service' => 'morning',
                'date' => '2026-06-22',
                'items' => [['type' => 'welcome', 'title' => 'Welcome']],
                'confidence' => 0.90,
            ]],
        ));

        // Stored result has a valid date/service/items so canApprove passes, but
        // the email body is malformed enough that the real import will throw.
        // We simulate this by directly mocking the import service to throw.
        $importService = \Mockery::mock(InboundEmailImportService::class);
        $importService->shouldReceive('storedParseResult')->andReturn(null);
        $importService->shouldReceive('storeParseResult')->andReturn(null);
        $importService->shouldReceive('import')->andThrow(new \RuntimeException('Database exploded'));
        $this->app->instance(InboundEmailImportService::class, $importService);

        $email = InboundEmail::factory()->create([
            'subject' => 'Order of Service - 2026-06-22 AM',
            'body_plain' => "Welcome\nSermon",
            'status' => InboundEmailStatus::Pending->value,
            'received_at' => '2026-06-20 09:00:00',
        ]);

        $result = app(ApproveInboundEmailImport::class)->execute($email, $this->admin->id);

        $this->assertIsString($result);
        $this->assertStringContainsString('Unable to approve', $result);
    }
}
