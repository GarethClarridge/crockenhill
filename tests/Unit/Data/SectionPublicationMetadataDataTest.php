<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\SectionPublicationMetadata;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SectionPublicationMetadataDataTest extends TestCase
{
    // ── fromArray ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_creates_from_full_array(): void
    {
        $metadata = SectionPublicationMetadata::fromArray([
            'approved_signature' => 'admin@example.com',
            'approved_at' => '2024-01-15T10:00:00Z',
            'batch_approvals' => [
                ['batch_id' => 1, 'approved_at' => '2024-01-10T09:00:00Z'],
            ],
        ]);

        $this->assertSame('admin@example.com', $metadata->approvedSignature);
        $this->assertSame('2024-01-15T10:00:00Z', $metadata->approvedAt);
        $this->assertCount(1, $metadata->batchApprovals);
    }

    #[Test]
    public function it_creates_from_minimal_array(): void
    {
        $metadata = SectionPublicationMetadata::fromArray([]);

        $this->assertNull($metadata->approvedSignature);
        $this->assertNull($metadata->approvedAt);
        $this->assertSame([], $metadata->batchApprovals);
    }

    #[Test]
    public function it_returns_null_for_non_array(): void
    {
        $this->assertNull(SectionPublicationMetadata::fromArray(null));
        $this->assertNull(SectionPublicationMetadata::fromArray('string'));
        $this->assertNull(SectionPublicationMetadata::fromArray(42));
    }

    #[Test]
    public function it_trims_whitespace_from_string_fields(): void
    {
        $metadata = SectionPublicationMetadata::fromArray([
            'approved_signature' => '  admin@example.com  ',
        ]);

        $this->assertSame('admin@example.com', $metadata->approvedSignature);
    }

    #[Test]
    public function it_coerces_empty_string_fields_to_null(): void
    {
        $metadata = SectionPublicationMetadata::fromArray([
            'approved_signature' => '',
            'approved_at' => '   ',
        ]);

        $this->assertNull($metadata->approvedSignature);
        $this->assertNull($metadata->approvedAt);
    }

    #[Test]
    public function it_filters_non_array_entries_from_batch_approvals(): void
    {
        $metadata = SectionPublicationMetadata::fromArray([
            'batch_approvals' => [
                ['batch_id' => 1],
                'not-an-array',
                42,
                ['batch_id' => 2],
            ],
        ]);

        $this->assertCount(2, $metadata->batchApprovals);
    }

    // ── toArray ───────────────────────────────────────────────────────────────

    #[Test]
    public function it_round_trips_via_to_array(): void
    {
        $input = [
            'approved_signature' => 'signer@example.com',
            'approved_at' => '2024-06-01T12:00:00Z',
            'batch_approvals' => [
                ['batch_id' => 10, 'note' => 'first batch'],
            ],
        ];

        $metadata = SectionPublicationMetadata::fromArray($input);
        $output = $metadata->toArray();

        $this->assertSame('signer@example.com', $output['approved_signature']);
        $this->assertSame('2024-06-01T12:00:00Z', $output['approved_at']);
        $this->assertCount(1, $output['batch_approvals']);
    }

    #[Test]
    public function it_omits_null_fields_from_to_array(): void
    {
        $metadata = SectionPublicationMetadata::fromArray([]);

        $output = $metadata->toArray();

        $this->assertArrayNotHasKey('approved_signature', $output);
        $this->assertArrayNotHasKey('approved_at', $output);
        $this->assertArrayNotHasKey('batch_approvals', $output);
    }

    #[Test]
    public function it_preserves_raw_unknown_fields_in_to_array(): void
    {
        $metadata = SectionPublicationMetadata::fromArray([
            'custom_flag' => true,
            'extra_data' => ['key' => 'value'],
        ]);

        $output = $metadata->toArray();

        $this->assertTrue($output['custom_flag']);
        $this->assertSame(['key' => 'value'], $output['extra_data']);
    }

    // ── ArrayAccess / JsonSerializable ────────────────────────────────────────

    #[Test]
    public function it_is_readable_via_array_access(): void
    {
        $metadata = SectionPublicationMetadata::fromArray([
            'approved_signature' => 'reader@example.com',
        ]);

        $this->assertSame('reader@example.com', $metadata['approved_signature']);
    }

    #[Test]
    public function it_throws_on_array_write(): void
    {
        $metadata = SectionPublicationMetadata::fromArray([]);

        $this->expectException(LogicException::class);
        $metadata['key'] = 'value'; // @phpstan-ignore-line
    }

    #[Test]
    public function it_serialises_to_json(): void
    {
        $metadata = SectionPublicationMetadata::fromArray([
            'approved_signature' => 'json@example.com',
        ]);

        $json = json_encode($metadata);
        $decoded = json_decode($json, true);

        $this->assertSame('json@example.com', $decoded['approved_signature']);
    }

    #[Test]
    public function offset_exists_returns_true_for_present_key(): void
    {
        $metadata = SectionPublicationMetadata::fromArray([
            'approved_signature' => 'sig@example.com',
        ]);

        $this->assertTrue(isset($metadata['approved_signature']));
        $this->assertFalse(isset($metadata['nonexistent_key']));
    }
}
