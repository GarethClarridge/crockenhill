<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\InboundEmail;

use App\Actions\InboundEmail\RejectInboundEmail;
use App\Enums\InboundEmailStatus;
use App\Models\InboundEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RejectInboundEmailTest extends TestCase
{
    use RefreshDatabase;

    private RejectInboundEmail $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(RejectInboundEmail::class);
    }

    #[Test]
    public function it_marks_the_email_as_rejected_with_audit_metadata(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::PENDING->value,
        ]);

        $this->action->execute($email, $admin->id);

        $email->refresh();

        $this->assertSame(InboundEmailStatus::REJECTED, $email->status);
        $this->assertSame($admin->id, $email->processing_metadata['review']['rejected_by_user_id'] ?? null);
        $this->assertNotNull($email->processing_metadata['review']['rejected_at'] ?? null);
    }

    #[Test]
    public function it_merges_rejection_metadata_without_overwriting_existing_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::PENDING->value,
            'processing_metadata' => [
                'parsing' => ['resolved_date' => '2026-06-29'],
                'review' => ['notes' => 'This should be preserved'],
            ],
        ]);

        $this->action->execute($email, $admin->id);

        $email->refresh();

        $this->assertSame(InboundEmailStatus::REJECTED, $email->status);
        $this->assertSame('This should be preserved', $email->processing_metadata['review']['notes'] ?? null);
        $this->assertSame($admin->id, $email->processing_metadata['review']['rejected_by_user_id'] ?? null);
        $this->assertSame('2026-06-29', $email->processing_metadata['parsing']['resolved_date'] ?? null);
    }

    #[Test]
    public function it_can_reject_a_failed_email(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::FAILED->value,
        ]);

        $this->action->execute($email, $admin->id);

        $email->refresh();

        $this->assertSame(InboundEmailStatus::REJECTED, $email->status);
        $this->assertSame($admin->id, $email->processing_metadata['review']['rejected_by_user_id'] ?? null);
    }
}
