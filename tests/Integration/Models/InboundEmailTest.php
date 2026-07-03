<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\InboundEmailStatus;
use App\Models\InboundEmail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboundEmailTest extends TestCase
{
    use RefreshDatabase;

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

    #[Test]
    public function it_trims_from_and_subject(): void
    {
        $email = new InboundEmail([
            'message_id' => '<trim@example.com>',
            'from' => '  Sender <sender@example.com>  ',
            'subject' => '  Test Subject  ',
            'received_at' => now(),
            'status' => InboundEmailStatus::Pending,
        ]);

        $email->save();

        $this->assertEquals('Sender <sender@example.com>', $email->from);
        $this->assertEquals('Test Subject', $email->subject);
    }

    #[Test]
    public function it_database_rejects_empty_from(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('inbound_emails_from_format_check');

        DB::table('inbound_emails')->insert([
            'message_id' => '<empty-from@example.com>',
            'from' => '',
            'subject' => 'Subject',
            'received_at' => now(),
            'status' => InboundEmailStatus::Pending->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_database_rejects_untrimmed_from(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('inbound_emails_from_format_check');

        DB::table('inbound_emails')->insert([
            'message_id' => '<untrimmed-from@example.com>',
            'from' => '  untrimmed  ',
            'subject' => 'Subject',
            'received_at' => now(),
            'status' => InboundEmailStatus::Pending->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_database_rejects_empty_subject(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('inbound_emails_subject_format_check');

        DB::table('inbound_emails')->insert([
            'message_id' => '<empty-subject@example.com>',
            'from' => 'Sender',
            'subject' => '',
            'received_at' => now(),
            'status' => InboundEmailStatus::Pending->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_validates_message_id_uniqueness(): void
    {
        InboundEmail::factory()->create(['message_id' => '<duplicate@example.com>']);

        $rules = InboundEmail::validationRules();
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['message_id' => '<duplicate@example.com>'],
            ['message_id' => $rules['message_id']]
        );

        $this->assertTrue($validator->fails());
        $this->assertEquals('The message id has already been taken.', $validator->errors()->first('message_id'));
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $rules = InboundEmail::validationRules();

        $validator = \Illuminate\Support\Facades\Validator::make(
            ['from' => '', 'subject' => ''],
            ['from' => $rules['from'], 'subject' => $rules['subject']]
        );

        $this->assertTrue($validator->fails());
        $this->assertEquals('The from field is required.', $validator->errors()->first('from'));
        $this->assertEquals('The subject field is required.', $validator->errors()->first('subject'));
    }
}
