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
    #[Test]
    public function semantic_annotations_use_a_fresh_cache_namespace_without_invalidating_the_legacy_namespace(): void
    {
        $binding = new OosArchiveParseCacheBinding('parser-commit');
        $entry = $this->entry();

        config()->set('service-tracking.email_parsing.implementation', 'legacy');
        $legacy = $binding->rawCacheKey($entry, 'archive-v13');
        $legacyHash = $binding->rawCacheKeyHash($entry, 'archive-v13');

        config()->set('service-tracking.email_parsing.implementation', 'semantic_annotations');
        $semantic = $binding->rawCacheKey($entry, 'archive-v13');
        $semanticHash = $binding->rawCacheKeyHash($entry, 'archive-v13');

        $this->assertSame('archive-v13', $legacy['parser_version']);
        $this->assertSame('archive-v13:semantic-annotations-v1', $semantic['parser_version']);
        $this->assertNotSame($legacyHash, $semanticHash);
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
