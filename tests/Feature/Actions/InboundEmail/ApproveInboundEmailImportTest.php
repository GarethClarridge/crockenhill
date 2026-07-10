<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\InboundEmail;

use App\Actions\InboundEmail\ApproveInboundEmailImport;
use App\Data\OosEmailImportResult;
use App\Data\OosEmailItemExtractionResult;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\User;
use App\Services\Email\InboundEmailImportService;
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
        ));

        $email = InboundEmail::factory()->create([
            'subject' => 'Order of Service - 2026-06-22 AM',
            'body_plain' => "Welcome\nSermon",
            'status' => InboundEmailStatus::Pending->value,
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
        ]);

        $result = app(ApproveInboundEmailImport::class)->execute($email, $this->admin->id);

        $this->assertIsString($result);
        $this->assertStringContainsString('Unable to approve', $result);
    }
}
