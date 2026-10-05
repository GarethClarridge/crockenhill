<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use InvalidArgumentException;

/**
 * The checks a sermon's final spans must pass after every adjustment — membership, review,
 * cue-safe widening and merging — before extraction may execute them (S6). Kept apart from
 * how the plan is composed, so every later adjustment is judged by the same rules.
 *
 * Spans no recording could hold are refused outright: they mean a defect upstream, not a
 * question for an operator. Spans that are possible but would publish the wrong content are
 * returned as violations, which send the plan to review.
 */
class SermonPublicationPlanValidator
{
    public function __construct(private readonly CueSafeExtractionPlan $cutPlans) {}

    /**
     * @param  list<ServiceSection>  $selected  The sections the plan chose to publish
     * @param  list<array{start_time: float, end_time: float}>  $spans  The final cut, after every adjustment
     * @return list<array{kind: 'selected_section_not_cut'|'crosses_held_section', section_ids: list<int>}>
     */
    public function validate(MediaProcessingLog $log, array $selected, array $spans): array
    {
        $this->refuseImpossible($log, $spans);

        $violations = [];

        // An edge may move inward to a pause and spans may merge, but each chosen section
        // must still reach the output.
        $dropped = array_values(array_map(
            static fn (ServiceSection $section): int => $section->id,
            array_filter($selected, static fn (ServiceSection $section): bool => ! array_any(
                $spans,
                static fn (array $span): bool => $span['start_time'] < (float) $section->end_time && $span['end_time'] > (float) $section->start_time,
            )),
        ));

        if ($dropped !== []) {
            $violations[] = ['kind' => 'selected_section_not_cut', 'section_ids' => $dropped];
        }

        // Selected sections answer for their own holds; a widened edge must not carry another
        // section's held content in, whatever authority the selected sections have (F09).
        $crossed = $this->cutPlans->heldSectionsCrossed(
            $log,
            $spans,
            array_map(static fn (ServiceSection $section): int => $section->id, $selected),
        );

        if ($crossed !== []) {
            $violations[] = ['kind' => 'crosses_held_section', 'section_ids' => $crossed];
        }

        return $violations;
    }

    /** @param  list<array{start_time: float, end_time: float}>  $spans */
    private function refuseImpossible(MediaProcessingLog $log, array $spans): void
    {
        if ($spans === []) {
            throw new InvalidArgumentException('A sermon plan must cut at least one span');
        }

        $previousEnd = 0.0;

        foreach ($spans as $span) {
            $start = $span['start_time'];
            $end = $span['end_time'];

            if (! is_finite($start) || ! is_finite($end) || $start < $previousEnd || $end <= $start
                || ($log->duration !== null && $end > $log->duration + 0.001)) {
                throw new InvalidArgumentException('Final cut bounds are unordered, overlapping or outside source');
            }

            $previousEnd = $end;
        }
    }
}
