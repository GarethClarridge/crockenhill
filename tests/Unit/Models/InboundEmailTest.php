<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\InboundEmailStatus;
use App\Models\InboundEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboundEmailTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_can_be_created_via_factory(): void
    {
        $email = InboundEmail::factory()->create([
            'subject' => 'Test Subject',
        ]);

        $this->assertDatabaseHas('inbound_emails', [
            'id' => $email->id,
            'subject' => 'Test Subject',
        ]);
    }

    #[Test]
    public function it_casts_attributes_correctly(): void
    {
        $receivedAt = now()->subMinutes(10)->startOfSecond();
        $email = InboundEmail::factory()->create([
            'received_at' => $receivedAt,
            'status' => InboundEmailStatus::Processed,
            'processing_metadata' => ['key' => 'value'],
        ]);

        $email = $email->fresh();

        $this->assertNotNull($email);
        $this->assertInstanceOf(Carbon::class, $email->received_at);
        $this->assertTrue($email->received_at->equalTo($receivedAt));
        $this->assertInstanceOf(InboundEmailStatus::class, $email->status);
        $this->assertSame(InboundEmailStatus::Processed, $email->status);
        $this->assertIsArray($email->processing_metadata);
        $this->assertSame(['key' => 'value'], $email->processing_metadata);
    }

    #[Test]
    public function it_has_fillable_attributes(): void
    {
        $data = [
            'message_id' => '<test@example.com>',
            'from' => 'Sender <sender@example.com>',
            'subject' => 'Subject',
            'body_plain' => 'Plain body',
            'body_html' => '<p>HTML body</p>',
            'received_at' => now()->startOfSecond(),
            'status' => InboundEmailStatus::Pending,
            'processing_metadata' => ['foo' => 'bar'],
        ];

        $email = new InboundEmail($data);

        foreach ($data as $key => $value) {
            $this->assertEquals($value, $email->{$key});
        }
    }
}
