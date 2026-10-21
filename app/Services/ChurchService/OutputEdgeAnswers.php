<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\ServiceSection;

/**
 * An operator's "start here" and "end here" for one sermon's, talk's or song's output, as the cut planner
 * reads them ({@see CueSafeExtractionPlan::forSpans()}). Each is a recording time chosen by ear,
 * kept on the section that owns the output (the sermon, talk or song), so it follows that content
 * through re-detection as a content hold does ({@see ServiceSectionSyncService}). It applies to
 * the output's first start or last end only, after every edge rule.
 *
 * @phpstan-type EdgeRecord array{time: float, set_by_user_id: int, set_at: string, from_time: float, section_span: array{start_time: float, end_time: float}, output_section_ids?: list<int>, note?: string}
 */
final readonly class OutputEdgeAnswers
{
    /** Where the answers are kept on the owning section's metadata, keyed `start` and `end`. */
    public const METADATA_KEY = 'output_edge_answers';

    /** Where earlier answers, replaced or cleared, are kept beside them. */
    public const HISTORY_KEY = 'history';

    /**
     * @param  array{start?: EdgeRecord, end?: EdgeRecord}  $answers
     * @param  list<int>  $outputSectionIds  The sections the output cuts, the owner among them
     */
    private function __construct(
        public int $ownerSectionId,
        public string $ownerTitle,
        public array $answers,
        public array $outputSectionIds,
    ) {}

    /** @param  list<ServiceSection>  $outputSections */
    public static function of(ServiceSection $owner, array $outputSections): self
    {
        return new self(
            $owner->id,
            (string) $owner->title,
            self::recordsOf($owner),
            array_values(array_unique([$owner->id, ...array_map(static fn (ServiceSection $section): int => $section->id, $outputSections)])),
        );
    }

    /**
     * The live answers on a section, skipping any malformed record.
     *
     * @return array{start?: EdgeRecord, end?: EdgeRecord}
     */
    public static function recordsOf(ServiceSection $section): array
    {
        $stored = $section->metadata?->raw[self::METADATA_KEY] ?? null;
        $records = [];

        foreach (['start', 'end'] as $edge) {
            $record = is_array($stored) ? ($stored[$edge] ?? null) : null;

            if (is_array($record) && is_numeric($record['time'] ?? null) && is_finite((float) $record['time'])) {
                $records[$edge] = [...$record, 'time' => (float) $record['time']];
            }
        }

        /** @var array{start?: EdgeRecord, end?: EdgeRecord} $records */
        return $records;
    }

    public function isEmpty(): bool
    {
        return $this->answers === [];
    }
}
