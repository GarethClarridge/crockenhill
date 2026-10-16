<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Actions\HoldSectionForContentReview;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeAnswers;
use App\Services\ChurchService\TalkReadingPairing;
use App\Support\ServiceTimestamp;
use Illuminate\Validation\ValidationException;

/**
 * Record an operator's "start here" or "end here" for a sermon's or a talk's output, chosen by ear
 * on the service screen ({@see OutputEdgeAnswers}). The cut planner applies it on the next cut,
 * after every edge rule ({@see CueSafeExtractionPlan::forSpans()}), and refuses it there if it
 * would cut into other content, so nothing needs decoding first.
 *
 * The answer is kept on the section that owns the output, with who set it, when, the edge it
 * replaced and the section's span at the time; an earlier answer goes to its history. Setting an
 * edge confirms nothing and releases no content hold. Like the talk recut {@see SaveServiceSection}
 * already offers, a talk's clip is returned to candidate preparation; a sermon's re-cut stays
 * explicit (`sermons:re-extract`).
 */
class SetOutputEdge
{
    public function __construct(
        private readonly TalkReadingPairing $talkReadings,
        private readonly ReturnTalkCandidateForRecut $returnForRecut,
    ) {}

    /**
     * @param  'start'|'end'  $edge
     * @param  string|null  $at  `h:mm:ss`, `m:ss` or seconds into the recording; null or blank clears the answer
     *
     * @throws ValidationException
     */
    public function execute(ServiceSection $section, string $edge, ?string $at, int $userId, string $note = ''): void
    {
        $field = "output_edge.{$edge}";
        $section->loadMissing('processingLog');
        $output = $this->outputSections($section);

        if ($output === null) {
            throw ValidationException::withMessages([$field => 'Only a sermon\'s or a talk\'s own start and end can be set here.']);
        }

        if ($section->publication_status === ServiceSectionPublicationStatus::Published) {
            throw ValidationException::withMessages([$field => 'Published sections cannot be recut here.']);
        }

        $time = null;

        if ($at !== null && trim($at) !== '') {
            $time = ServiceTimestamp::parse($at);

            if ($time === null) {
                throw ValidationException::withMessages([$field => 'Enter the time as h:mm:ss, m:ss or seconds into the recording.']);
            }

            $this->refuseImpossible($section, $output, $edge, $time, $field);
        }

        $first = $output[0];
        $last = $output[count($output) - 1];
        $metadata = $section->metadata?->toArray() ?? [];
        $stored = is_array($metadata[OutputEdgeAnswers::METADATA_KEY] ?? null) ? $metadata[OutputEdgeAnswers::METADATA_KEY] : [];
        $previous = is_array($stored[$edge] ?? null) ? $stored[$edge] : null;

        if ($previous !== null) {
            $stored[OutputEdgeAnswers::HISTORY_KEY] = [...(is_array($stored[OutputEdgeAnswers::HISTORY_KEY] ?? null) ? $stored[OutputEdgeAnswers::HISTORY_KEY] : []),
                [...$previous, 'edge' => $edge, 'replaced_at' => now()->toIso8601String(), 'replaced_by_user_id' => $userId, 'replaced_with' => $time]];
        }

        if ($time === null) {
            unset($stored[$edge]);
        } else {
            $stored[$edge] = [
                'time' => $time,
                'set_by_user_id' => $userId,
                'set_at' => now()->toIso8601String(),
                'from_time' => (float) ($edge === 'start' ? $first->start_time : $last->end_time),
                'section_span' => ['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time],
                'output_section_ids' => array_map(static fn (ServiceSection $part): int => $part->id, $output),
                'note' => trim($note),
            ];
        }

        if ($stored === []) {
            unset($metadata[OutputEdgeAnswers::METADATA_KEY]);
        } else {
            $metadata[OutputEdgeAnswers::METADATA_KEY] = $stored;
        }

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);

        if ($section->section_type !== ServiceSectionType::ShortTalk) {
            $section->save();

            return;
        }

        $this->returnForRecut->apply($section);
        $section->save();
        PrepareSectionPublicationCandidates::dispatchStandalone($section->processingLog);
    }

    /**
     * The sections the output the section owns cuts, in time order: a sermon's composition, or a
     * talk with any reading it is answered to go with. Null when the section owns no such output.
     *
     * @return list<ServiceSection>|null
     */
    public function outputSections(ServiceSection $section): ?array
    {
        if ($section->section_type === ServiceSectionType::ShortTalk) {
            return $this->talkReadings->sectionsCut($section);
        }

        if ($section->section_type !== ServiceSectionType::Sermon) {
            return null;
        }

        $composition = $section->processingLog->processing_metadata?->raw['sermon_composition'] ?? null;

        if (! is_array($composition) || ($composition['sermon_section_id'] ?? null) !== $section->id) {
            return [$section];
        }

        /** @var list<ServiceSection> $parts */
        $parts = array_values($section->processingLog->serviceSections()->whereIn('id', is_array($composition['selected_section_ids'] ?? null) ? $composition['selected_section_ids'] : [])
            ->orderBy('start_time')->orderBy('id')->get()->all());

        return $parts === [] ? [$section] : $parts;
    }

    /**
     * What section bounds alone can tell, before any decode: the time is in the recording, keeps
     * every part, comes before the end (or after the start) already set, and reaches into no held
     * section the output does not cut. Whether it cuts into other content is the planner's to say,
     * against its own edge.
     *
     * @param  list<ServiceSection>  $output
     *
     * @throws ValidationException
     */
    private function refuseImpossible(ServiceSection $section, array $output, string $edge, float $time, string $field): void
    {
        $duration = $section->processingLog->duration;
        $first = $output[0];
        $last = $output[count($output) - 1];
        $other = OutputEdgeAnswers::recordsOf($section)[$edge === 'start' ? 'end' : 'start']['time'] ?? null;
        $error = match (true) {
            $time < 0.0 || ($duration !== null && $time > (float) $duration) => sprintf('%s is outside the recording (0:00–%s).',
                ServiceTimestamp::format($time), ServiceTimestamp::format((float) $duration)),
            $edge === 'start' && $time >= (float) $first->end_time => sprintf('A start at %s would leave out the whole first part, which ends at %s.',
                ServiceTimestamp::format($time), ServiceTimestamp::format((float) $first->end_time)),
            $edge === 'end' && $time <= (float) $last->start_time => sprintf('An end at %s would leave out the whole last part, which starts at %s.',
                ServiceTimestamp::format($time), ServiceTimestamp::format((float) $last->start_time)),
            $other !== null && ($edge === 'start' ? $time >= $other : $time <= $other) => sprintf('The %s must come %s the %s already set at %s.',
                $edge, $edge === 'start' ? 'before' : 'after', $edge === 'start' ? 'end' : 'start', ServiceTimestamp::format($other)),
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages([$field => $error]);
        }

        $bound = (float) ($edge === 'start' ? $first->start_time : $last->end_time);
        [$from, $to] = [min($bound, $time), max($bound, $time)];
        $held = $section->processingLog->serviceSections()
            ->whereNotIn('id', array_map(static fn (ServiceSection $part): int => $part->id, $output))
            ->where('start_time', '<', $to)->where('end_time', '>', $from)->orderBy('start_time')->get()
            ->first(static fn (ServiceSection $candidate): bool => HoldSectionForContentReview::isHeld($candidate->metadata->reviewFlags ?? []));

        if ($held instanceof ServiceSection) {
            throw ValidationException::withMessages([$field => sprintf('The %s at %s reaches into “%s” (§%d), which is held for content review.',
                $edge, ServiceTimestamp::format($time), (string) $held->title, $held->id)]);
        }
    }
}
