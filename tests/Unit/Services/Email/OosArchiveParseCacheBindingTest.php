<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosArchiveEntry;
use App\Services\Email\OosArchiveParseCacheBinding;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosArchiveParseCacheBindingTest extends TestCase
{
    /**
     * Invariant 11: a legacy cache row is never reinterpreted as an annotation result.
     *
     * The namespace suffix used to be conditional on the implementation config. That key is gone
     * with the legacy parser, but the *rows* it wrote are database state and outlive the code, so
     * the suffix is now unconditional — the legacy namespace has to stay permanently unreachable
     * rather than become reachable again the moment the key selecting it disappeared.
     */
    #[Test]
    public function the_legacy_cache_namespace_stays_permanently_unreachable(): void
    {
        $binding = new OosArchiveParseCacheBinding('parser-commit');
        $entry = $this->entry();

        $key = $binding->rawCacheKey($entry, 'archive-v13');

        $this->assertSame('archive-v13:semantic-annotations-v1', $key['parser_version']);
        $this->assertNotSame(
            $binding->rawCacheKeyHash($entry, 'archive-v13'),
            $binding->rawCacheKeyHash($entry, 'archive-v13:semantic-annotations-v1'),
            'A bare legacy parser version must not hash to the same key as the namespaced one.',
        );
    }

    private function entry(): OosArchiveEntry
    {
        return new OosArchiveEntry(
            index: 1,
            itemKey: 'email-1',
            subject: 'Order',
            bodyPlain: 'Welcome',
            groundTruthDate: '2026-08-23',
            contentScope: 'full',
            servicesPresent: ['morning'],
            itemLineCounts: [],
            curation: [
                'date_decision' => 'accepted',
                'date_decision_reason' => null,
                'parse_decision' => 'include',
                'content_scope' => 'full',
                'partial_scope_reason' => null,
                'payload' => 'body',
                'service_label' => 'morning',
                'title_override' => null,
                'supersedes' => null,
                'expected_item_count' => null,
                'decided_by' => null,
                'decided_at' => null,
                'decision_rule_version' => null,
            ],
            syntheticMessageId: 'email-1@example.test',
            sourceKey: 'source-1',
            supersedesSourceKey: null,
            inputHash: 'input-hash',
            syntheticReceivedAt: CarbonImmutable::parse('2026-08-19'),
        );
    }
}
