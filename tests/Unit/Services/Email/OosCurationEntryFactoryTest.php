<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosCurationPlan;
use App\Services\Email\OosCurationEntryFactory;
use App\Support\MarkdownFrontmatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OosCurationEntryFactoryTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_takes_every_identity_field_from_the_manifest_rather_than_the_payload(): void
    {
        // The payload's own words disagree with the manifest about which Sunday this was — the
        // real `2026-03-15-2` case. §7.3 makes the manifest authority, so the entry carries the
        // manifest's date while the email's subject reaches the extractor unaltered.
        $path = $this->payload(<<<'MARKDOWN'
            ---
            title: "Order of service [email title likely intended 15 February]"
            date: 2026-02-15
            source_subject: "Order of service for 15th March"
            ---

            Morning service
            Amazing Grace
            MARKDOWN);

        $entry = $this->factory()->entries($this->plan([
            'resolved_date' => '2026-02-15',
            'resolved_service' => 'morning',
        ]), ['2026-03-15-2' => $path])[0];

        $this->assertSame('2026-02-15', $entry->groundTruthDate);
        $this->assertSame(['morning'], $entry->servicesPresent);
        $this->assertSame('Order of service for 15th March', $entry->subject);
        $this->assertSame("Morning service\nAmazing Grace", $entry->bodyPlain);
        $this->assertSame(1, $entry->index);
        $this->assertTrue($entry->assertsFullOrder());
    }

    /**
     * The manifest already holds the digest of the bytes the operator approved, so "has the
     * source changed since the last run" is answered by that rather than by a second hash of a
     * normalised derivative of it.
     */
    #[Test]
    public function the_input_hash_is_the_approved_payload_digest(): void
    {
        $entry = $this->factory()->entries(
            $this->plan(['sha256' => str_repeat('b', 64)]),
            ['2026-02-15-am' => $this->payload("---\ndate: 2026-02-15\n---\n\nAmazing Grace")],
        )[0];

        $this->assertSame(str_repeat('b', 64), $entry->inputHash);
    }

    #[Test]
    public function it_carries_the_curation_decisions_into_the_entry(): void
    {
        $entry = $this->factory()->entries($this->plan([
            'date_decision' => 'inferred',
            'date_decision_reason' => 'derived from liturgical calendar',
            'parse_decision' => 'manifest-authoritative',
            'title_override' => 'Easter Sunday',
            'decided_by' => 'maintainer',
            'decided_at' => '2026-08-06T10:00:00+00:00',
        ]), ['2026-02-15-am' => $this->payload("---\ndate: 2026-02-15\n---\n\nAmazing Grace")])[0];

        $this->assertSame('inferred', $entry->curation['date_decision']);
        $this->assertSame('derived from liturgical calendar', $entry->curation['date_decision_reason']);
        $this->assertSame('manifest-authoritative', $entry->curation['parse_decision']);
        $this->assertSame('Easter Sunday', $entry->curation['title_override']);
        $this->assertSame('maintainer', $entry->curation['decided_by']);
    }

    /**
     * §7.5 keeps heuristic counts out of the manifest, so an entry that asserts none must expose
     * none — not a zero, and not a guess derived from the body.
     */
    #[Test]
    public function an_unasserted_item_count_produces_no_expected_counts_at_all(): void
    {
        $factory = $this->factory();
        $payload = ['2026-02-15-am' => $this->payload("---\ndate: 2026-02-15\n---\n\nOne\nTwo\nThree")];

        $this->assertSame([], $factory->entries($this->plan(), $payload)[0]->itemLineCounts);

        $asserted = $factory->entries(
            $this->plan(['expected_item_count' => 13, 'decided_by' => 'maintainer']),
            $payload,
        )[0];

        $this->assertSame(['morning' => 13], $asserted->itemLineCounts);
    }

    #[Test]
    public function it_falls_back_from_the_thread_subject_to_the_title_and_then_the_item_key(): void
    {
        $factory = $this->factory();

        $titled = $factory->entries($this->plan(), [
            '2026-02-15-am' => $this->payload("---\ndate: 2026-02-15\ntitle: \"Sunday 15 February 2026\"\n---\n\nAmazing Grace"),
        ])[0];
        $this->assertSame('Sunday 15 February 2026', $titled->subject);

        $bare = $factory->entries($this->plan(), [
            '2026-02-15-am' => $this->payload("---\ndate: 2026-02-15\n---\n\nAmazing Grace"),
        ])[0];
        $this->assertSame('2026-02-15-am', $bare->subject);
    }

    #[Test]
    public function the_synthetic_identity_is_deterministic_and_anchored_to_the_service_date(): void
    {
        $payload = ['2026-02-15-am' => $this->payload("---\ndate: 2026-02-15\n---\n\nAmazing Grace")];
        $factory = $this->factory();

        $first = $factory->entries($this->plan(), $payload)[0];
        $second = $factory->entries($this->plan(), $payload)[0];

        $this->assertSame($first->syntheticMessageId, $second->syntheticMessageId);
        $this->assertStringStartsWith('<oos-2026-02-15-am-', $first->syntheticMessageId);
        $this->assertSame('2026-02-13 09:00', $first->syntheticReceivedAt->format('Y-m-d H:i'));
    }

    #[Test]
    public function it_refuses_an_approved_entry_with_no_verified_payload_path(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No verified payload path for approved OoS entry 2026-02-15-am.');

        $this->factory()->entries($this->plan(), []);
    }

    #[Test]
    public function it_refuses_a_payload_that_is_nothing_but_frontmatter(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2026-02-15-am has an empty body');

        $this->factory()->entries(
            $this->plan(),
            ['2026-02-15-am' => $this->payload("---\ndate: 2026-02-15\n---\n\n   \n")],
        );
    }

    private function factory(): OosCurationEntryFactory
    {
        return new OosCurationEntryFactory(new MarkdownFrontmatter);
    }

    /** @param array<string, mixed> $overrides */
    private function plan(array $overrides = []): OosCurationPlan
    {
        $include = [
            'item_key' => '2026-02-15-am',
            'source_kind' => 'email',
            'relative_path' => '2026-02-15-am.md',
            'sha256' => str_repeat('a', 64),
            'byte_size' => 100,
            'payload' => 'verbatim',
            'verbatim_relative_path' => '2026-02-15-am.md',
            'formatted_relative_path' => null,
            'resolved_date' => '2026-02-15',
            'resolved_service' => 'morning',
            'service_label' => null,
            'title_override' => null,
            'date_decision' => 'explicit',
            'date_decision_reason' => null,
            'content_scope' => 'full',
            'partial_scope_reason' => null,
            'supersedes' => null,
            'parse_decision' => 'strict',
            'expected_item_count' => null,
            'decided_by' => null,
            'decided_at' => null,
            'decision_rule_version' => 'oos-curation-draft-v1',
        ];

        $include = [...$include, ...$overrides];

        if (isset($overrides['resolved_date'])) {
            $include['item_key'] = '2026-03-15-2';
        }

        return new OosCurationPlan('manifest-hash', 'plan-hash', [$include], [], 'oos-test-batch');
    }

    private function payload(string $contents): string
    {
        $path = sys_get_temp_dir().'/oos_factory_'.str_replace('.', '', uniqid('', true)).'.md';
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }
}
