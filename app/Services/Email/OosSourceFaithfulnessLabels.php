<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosSourceFaithfulnessVerdict;
use RuntimeException;

/**
 * The versioned adjudication-label artifact for the OoS parser model evaluation.
 *
 * One class holds both halves of the format on purpose. This repository has a recorded defect class
 * of shipping a validator with no producer, and an operator-authored evidence file is exactly where
 * that hurts: a hand-written artifact drifts from whatever the reader expects, and the reader then
 * either rejects everything or — far worse — quietly accepts a shape it misreads. So the comparator
 * *emits* the worksheet in this format, the operator fills in the verdict column, and the same class
 * reads it back.
 *
 * What the artifact is, and is not:
 *
 * - It records **faithfulness to the verbatim email**, decided by reading the source lines. It is
 *   not agreement with the hymn workbook or the OpenLP decks; a service can change after the email
 *   was written, so those answer a different question and decide nothing here.
 * - Its population is **every raw-discordant source**, enumerated once before any label is opened.
 *   There is no partial-labelling rule: with sources outstanding, each one could be a baseline-only
 *   win, so stopping early on favourable labels would be optional stopping in an efficiency costume.
 * - It is **create-once evidence**. The binding block ties it to the two exact parses it was read
 *   against, so a label set can never be replayed over a rerun that produced different output.
 *
 * "No schema validator" in the plan means no external JSON Schema dependency. It does not mean an
 * unvalidated artifact: the version constant and the runtime shape checks below are the validation.
 *
 * This one-shot surface is deleted once the Luna adoption report is accepted and no rerun remains,
 * or at historic-import IC8 closeout at the latest.
 */
class OosSourceFaithfulnessLabels
{
    public const Format = 'crockenhill-oos-source-faithfulness-labels';

    public const Version = 1;

    /** A worksheet is the emitted, unlabelled shape; only an adjudicated set may decide anything. */
    public const StatusWorksheet = 'worksheet';

    public const StatusAdjudicated = 'adjudicated';

    /** Every binding field, all of which must match the comparison that emitted the worksheet. */
    private const BindingKeys = [
        'baseline_arm',
        'candidate_arm',
        'baseline_model',
        'candidate_model',
        'source_key_list_hash',
        'baseline_projection_sha256',
        'candidate_projection_sha256',
    ];

    /**
     * @param  array<string, array{verdict:OosSourceFaithfulnessVerdict,item_counts:?array{truth_items:int,baseline_supported_items:int,candidate_supported_items:int},note:?string}>  $labels
     */
    private function __construct(private readonly array $labels) {}

    /**
     * Emit the worksheet the operator adjudicates.
     *
     * Every row arrives pre-filled with the evidence needed to decide it — which arm produced what,
     * over the union of both arms' plans — and with a null verdict. A null that survives into the
     * artifact is refused on read, so an unfinished worksheet cannot be mistaken for a finished one.
     *
     * @param  array<string, string>  $binding
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function worksheet(array $binding, array $rows): array
    {
        return [
            'format' => self::Format,
            'version' => self::Version,
            'status' => self::StatusWorksheet,
            'verdict_vocabulary' => array_map(
                static fn (OosSourceFaithfulnessVerdict $verdict): string => $verdict->value,
                OosSourceFaithfulnessVerdict::cases(),
            ),
            'instructions' => 'Read each source\'s verbatim lines and set "verdict" to one of '
                .'verdict_vocabulary: which arm, if either, reproduced what the email states. Both arms '
                .'can be wrong differently, which is neither_faithful, not a win for either. Where '
                .'"requires_item_counts" is true, also fill item_counts: truth_items is how many items '
                .'the email states across this source\'s services, and each supported count is how many '
                .'of those the arm extracted with the right type, order and source lines. Change '
                .'"status" to "adjudicated" only once every verdict is set. Label every row: a partial '
                .'set cannot decide the comparison.',
            'binding' => self::requireBinding($binding, 'emitted'),
            'labels' => array_map(
                static fn (array $row): array => $row + [
                    'verdict' => null,
                    'item_counts' => null,
                    'note' => null,
                ],
                $rows,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, string>  $expectedBinding
     */
    public static function fromArtifact(array $artifact, array $expectedBinding): self
    {
        if (($artifact['format'] ?? null) !== self::Format) {
            throw new RuntimeException('The truth artifact is not a source-faithfulness label set.');
        }

        if (($artifact['version'] ?? null) !== self::Version) {
            throw new RuntimeException('The truth artifact is version '
                .var_export($artifact['version'] ?? null, true)
                .'; this comparison reads version '.self::Version.' only.');
        }

        if (($artifact['status'] ?? null) !== self::StatusAdjudicated) {
            throw new RuntimeException('The truth artifact is still a worksheet. Set "status" to "'
                .self::StatusAdjudicated.'" once every verdict is filled in.');
        }

        $binding = $artifact['binding'] ?? null;

        if (! is_array($binding)) {
            throw new RuntimeException('The truth artifact carries no binding block.');
        }

        /** @var array<string, mixed> $binding */
        $recorded = self::requireBinding($binding, 'recorded');
        $expected = self::requireBinding($expectedBinding, 'expected');

        foreach (self::BindingKeys as $key) {
            if ($recorded[$key] !== $expected[$key]) {
                throw new RuntimeException("The truth artifact was adjudicated against a different run: {$key} is '{$recorded[$key]}', this comparison has '{$expected[$key]}'.");
            }
        }

        $rows = $artifact['labels'] ?? null;

        // An empty list is well formed — two arms can agree everywhere. Whether the list covers the
        // sources that actually need adjudicating is the comparison's question, not the format's.
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('The truth artifact carries no labels list.');
        }

        $labels = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException("Truth label {$index} is not an object.");
            }

            $sourceKey = $row['source_key'] ?? null;

            if (! is_string($sourceKey) || trim($sourceKey) === '') {
                throw new RuntimeException("Truth label {$index} carries no source key.");
            }

            if (isset($labels[$sourceKey])) {
                throw new RuntimeException("Source {$sourceKey} is labelled more than once.");
            }

            $labels[$sourceKey] = [
                'verdict' => self::verdict($row['verdict'] ?? null, $sourceKey),
                'item_counts' => self::itemCounts($row['item_counts'] ?? null, $sourceKey),
                'note' => is_string($row['note'] ?? null) ? $row['note'] : null,
            ];
        }

        return new self($labels);
    }

    public function verdictFor(string $sourceKey): ?OosSourceFaithfulnessVerdict
    {
        return $this->labels[$sourceKey]['verdict'] ?? null;
    }

    /** @return array{truth_items:int,baseline_supported_items:int,candidate_supported_items:int}|null */
    public function itemCountsFor(string $sourceKey): ?array
    {
        return $this->labels[$sourceKey]['item_counts'] ?? null;
    }

    /** @return list<string> */
    public function sourceKeys(): array
    {
        return array_keys($this->labels);
    }

    private static function verdict(mixed $value, string $sourceKey): OosSourceFaithfulnessVerdict
    {
        if ($value === null) {
            throw new RuntimeException("Source {$sourceKey} has no verdict. Every discordant source must be adjudicated; a partial label set cannot decide the comparison.");
        }

        if (! is_string($value)) {
            throw new RuntimeException("Source {$sourceKey} has a non-string verdict.");
        }

        $verdict = OosSourceFaithfulnessVerdict::tryFrom($value);

        if ($verdict === null) {
            throw new RuntimeException("Source {$sourceKey} has an unknown verdict '{$value}'.");
        }

        return $verdict;
    }

    /**
     * Item counts are optional in the *format* and required by the *analysis*, and the two checks
     * live apart deliberately: this class decides whether an artifact is well formed, and the
     * comparison decides which sources its item-recall guardrail needs counts for. Folding the
     * second into the first would make the format's validity depend on an analysis it cannot see.
     *
     * @return array{truth_items:int,baseline_supported_items:int,candidate_supported_items:int}|null
     */
    private static function itemCounts(mixed $value, string $sourceKey): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new RuntimeException("Source {$sourceKey} has malformed item counts.");
        }

        $counts = [];

        foreach (['truth_items', 'baseline_supported_items', 'candidate_supported_items'] as $field) {
            $count = $value[$field] ?? null;

            if (! is_int($count) || $count < 0) {
                throw new RuntimeException("Source {$sourceKey} has a missing or negative {$field}.");
            }

            $counts[$field] = $count;
        }

        if ($counts['truth_items'] === 0) {
            throw new RuntimeException("Source {$sourceKey} states zero truth items; a source with no items cannot be scored for item recall.");
        }

        foreach (['baseline_supported_items', 'candidate_supported_items'] as $field) {
            if ($counts[$field] > $counts['truth_items']) {
                throw new RuntimeException("Source {$sourceKey} supports more items in {$field} than the email states.");
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $binding
     * @return array<string, string>
     */
    private static function requireBinding(array $binding, string $label): array
    {
        $required = [];

        foreach (self::BindingKeys as $key) {
            $value = $binding[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException("The {$label} label binding is missing {$key}.");
            }

            $required[$key] = $value;
        }

        return $required;
    }
}
