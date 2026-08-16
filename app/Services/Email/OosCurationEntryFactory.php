<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosArchiveEntry;
use App\Data\OosCurationPlan;
use App\Data\VerifiedSourceSnapshot;
use App\Services\ChurchService\SourceAdapters\EmailSourceAdapter;
use App\Support\MarkdownFrontmatter;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Turns an approved §7.5 curation plan into the entries the evaluation and
 * import pipeline consumes.
 *
 * This replaces the deleted `OosArchiveMarkdownParser`, which split one
 * aggregate markdown file on `### ` headings and inferred each entry's date,
 * service and completeness from the heading text. The corpus is one file per order of
 * service and §7.3 makes the approved manifest mutation authority, so every
 * one of those inferences is now a decision read off the plan. Nothing here
 * guesses; the only things read from the payload file are the two the manifest
 * does not carry — the email's subject and its body.
 */
class OosCurationEntryFactory
{
    /** The corpus records dates but not send times, so only the hour is synthetic. */
    private const SyntheticArrivalDaysBefore = 2;

    private const SyntheticArrivalHour = 9;

    public function __construct(
        private readonly MarkdownFrontmatter $frontmatter,
    ) {}

    /**
     * @param  array<string, VerifiedSourceSnapshot|string>  $verifiedPayloads
     * @return list<OosArchiveEntry>
     */
    public function entries(OosCurationPlan $plan, array $verifiedPayloads): array
    {
        $entries = [];
        $sourceKeysByItemKey = [];

        foreach ($plan->includes as $include) {
            $sourceKeysByItemKey[$include['item_key']] = self::sourceKey(
                $include['item_key'],
                $include['resolved_service'],
                $include['resolved_date'],
            );
        }

        foreach ($this->orderedIncludes($plan->includes) as $offset => $include) {
            $payload = $verifiedPayloads[$include['item_key']] ?? throw new RuntimeException(
                "No verified payload path for approved OoS entry {$include['item_key']}."
            );

            if ($payload instanceof VerifiedSourceSnapshot) {
                if (! hash_equals($include['sha256'], $payload->approvedSha256)
                    || ! hash_equals($include['sha256'], $payload->observedSha256)
                    || $include['byte_size'] !== $payload->byteSize) {
                    throw new RuntimeException("Verified OoS payload snapshot does not match {$include['item_key']}. ");
                }

                $frontmatter = $this->frontmatter->parse($payload->contents);
                $body = trim($this->frontmatter->bodyFromContents($payload->contents));
            } else {
                // Compatibility for callers that have already materialised a test fixture.
                $frontmatter = $this->frontmatter->read($payload);
                $body = trim($this->frontmatter->body($payload));
            }

            if ($body === '') {
                throw new RuntimeException("Approved OoS entry {$include['item_key']} has an empty body.");
            }

            $entries[] = new OosArchiveEntry(
                index: $offset + 1,
                itemKey: $include['item_key'],
                subject: $this->subject($include['item_key'], $frontmatter),
                bodyPlain: $body,
                groundTruthDate: $include['resolved_date'],
                contentScope: $include['content_scope'],
                servicesPresent: [$include['resolved_service']],
                itemLineCounts: $include['expected_item_count'] === null
                    ? []
                    : [$include['resolved_service'] => $include['expected_item_count']],
                curation: [
                    'date_decision' => $include['date_decision'],
                    'date_decision_reason' => $include['date_decision_reason'],
                    'parse_decision' => $include['parse_decision'],
                    'content_scope' => $include['content_scope'],
                    'partial_scope_reason' => $include['partial_scope_reason'],
                    'payload' => $include['payload'],
                    'service_label' => $include['service_label'],
                    'title_override' => $include['title_override'],
                    'supersedes' => $include['supersedes'],
                    'expected_item_count' => $include['expected_item_count'],
                    'decided_by' => $include['decided_by'],
                    'decided_at' => $include['decided_at'],
                    'decision_rule_version' => $include['decision_rule_version'],
                ],
                syntheticMessageId: $this->messageId($include['item_key']),
                sourceKey: $sourceKeysByItemKey[$include['item_key']],
                supersedesSourceKey: $include['supersedes'] === null
                    ? null
                    : $sourceKeysByItemKey[$include['supersedes']],
                /**
                 * The manifest's own payload digest, so "has the source changed
                 * since the last run" is answered by the bytes the operator
                 * approved rather than by a second hash of a normalised
                 * derivative of them.
                 */
                inputHash: $include['sha256'],
                syntheticReceivedAt: $this->receivedAt($include['source_date'], $include['resolved_date']),
            );
        }

        return $entries;
    }

    /**
     * The subject reaches the extractor alongside the body, so it is the email's
     * own words or nothing invented: the thread subject where the corpus kept
     * one, otherwise the file's title.
     *
     * A subject may contradict the manifest — `2026-03-15-2` reads "15th March"
     * for a 15 February service — and it is passed through unaltered. The
     * manifest is the date authority, and the resulting `date_mismatch` is a
     * reported measurement of what the extractor believed, not a fault.
     *
     * @param  array<string, string>  $frontmatter
     */
    private function subject(string $itemKey, array $frontmatter): string
    {
        foreach (['source_subject', 'title'] as $key) {
            $value = trim($frontmatter[$key] ?? '');

            if ($value !== '') {
                return $value;
            }
        }

        return $itemKey;
    }

    /**
     * Readable to an operator grepping `inbound_emails`, and unique even if a
     * future item key carries characters a message id cannot.
     *
     * Public and static because it is the *identity* of an approved entry rather
     * than an implementation detail of building one:
     * {@see OosApprovedCorpus} derives the same value from the
     * manifest to state what the corpus is expected to contain. Two independent
     * copies of this rule would let the expectation and the import drift apart
     * while both looked right.
     */
    public static function messageId(string $itemKey): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($itemKey)), '-');

        return sprintf('<oos-%s-%s@crockenhill.local>', $slug, substr(sha1($itemKey), 0, 8));
    }

    /**
     * The key one approved entry's assertion about one service slot is stored
     * under. Mirrors {@see EmailSourceAdapter::sourceKeyFor()},
     * whose plan key is the same `service:date` pair.
     */
    public static function sourceKey(string $itemKey, string $service, string $date): string
    {
        return self::messageId($itemKey).'|'.$service.':'.$date;
    }

    /**
     * The message-id half of a source key — the approved entry a staged revision
     * came from, whichever service slot it ended up asserting.
     */
    public static function originOf(string $sourceKey): ?string
    {
        $origin = explode('|', $sourceKey, 2)[0];

        return $origin === '' ? null : $origin;
    }

    /**
     * A correction cannot resolve a predecessor that has not reached the
     * service yet. The manifest's include order is not lineage order, so make
     * the dependency explicit without changing any identity field.
     *
     * @param  list<array<string, mixed>>  $includes
     * @return list<array<string, mixed>>
     */
    private function orderedIncludes(array $includes): array
    {
        $byKey = [];
        foreach ($includes as $include) {
            $byKey[$include['item_key']] = $include;
        }

        $ordered = [];
        $visited = [];
        $visit = function (array $include) use (&$visit, &$ordered, &$visited, $byKey): void {
            $itemKey = $include['item_key'];

            if (isset($visited[$itemKey])) {
                return;
            }

            $predecessor = $include['supersedes'];
            if ($predecessor !== null) {
                $visit($byKey[$predecessor]);
            }

            $visited[$itemKey] = true;
            $ordered[] = $include;
        };

        foreach ($includes as $include) {
            $visit($include);
        }

        return $ordered;
    }

    private function receivedAt(?string $sourceDate, string $resolvedDate): CarbonImmutable
    {
        $date = $sourceDate ?? CarbonImmutable::parse($resolvedDate, 'Europe/London')
            ->subDays(self::SyntheticArrivalDaysBefore)
            ->toDateString();

        return CarbonImmutable::parse($date, 'Europe/London')->setTime(self::SyntheticArrivalHour, 0);
    }
}
