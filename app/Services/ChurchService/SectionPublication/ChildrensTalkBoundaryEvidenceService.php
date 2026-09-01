<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Data\ChurchServiceTranscript;
use App\Models\ServiceSection;
use App\Support\ServiceArtifactDisk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Records the evidence around an inclusive children's-talk candidate.
 *
 * Children's-talk boundaries remain a mandatory human decision. This service
 * records the tail of the candidate and the sections which follow it so the
 * reviewer can decide whether a prayer or song introduction is editorially
 * integral. It never changes the candidate interval or publishes media.
 */
class ChildrensTalkBoundaryEvidenceService
{
    public const METADATA_KEY = 'childrens_talk_boundary';

    private const VERSION = 1;

    private const TAIL_EVIDENCE_WINDOW_SECONDS = 180.0;

    private const MAX_RECORDED_TAIL_CUES = 12;

    private const MAX_FOLLOWING_SECTIONS = 3;

    /**
     * @return array<string, mixed>
     */
    public function assess(ServiceSection $section): array
    {
        $start = (float) $section->start_time;
        $end = (float) $section->end_time;
        $transcriptInput = $this->loadTranscript($section);
        $followingSections = $this->followingSections($section);

        return [
            'version' => self::VERSION,
            'candidate' => [
                'kind' => 'inclusive',
                'start_time' => $start,
                'end_time' => $end,
                'duration' => max(0.0, $end - $start),
            ],
            'action' => 'retain_inclusive_candidate',
            'mandatory_approval' => true,
            'recut' => [
                'allowed' => true,
                'field' => 'end_time',
                'direction' => 'shorten_only',
                'minimum_end_time' => $start,
                'maximum_end_time' => $end,
            ],
            'tail' => $this->tailEvidence($transcriptInput['transcript'], $transcriptInput['status'], $start, $end),
            'following_sections' => $followingSections,
            'reviewed_recuts' => $this->reviewedRecuts($section),
            'inputs' => [
                'service_transcript' => [
                    'path' => $transcriptInput['path'],
                    'status' => $transcriptInput['status'],
                ],
            ],
            'recorded_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{transcript: ChurchServiceTranscript|null, path: string|null, status: string}
     */
    private function loadTranscript(ServiceSection $section): array
    {
        $transcriptPath = $section->processingLog->serviceTranscriptPath();

        if ($transcriptPath === null) {
            return [
                'transcript' => null,
                'path' => null,
                'status' => 'not_recorded',
            ];
        }

        try {
            $disk = ServiceArtifactDisk::for($transcriptPath);

            if (! Storage::disk($disk)->exists($transcriptPath)) {
                return [
                    'transcript' => null,
                    'path' => $transcriptPath,
                    'status' => 'missing',
                ];
            }

            /** @var mixed $decoded */
            $decoded = json_decode(
                (string) Storage::disk($disk)->get($transcriptPath),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $transcript = ChurchServiceTranscript::fromArray($decoded);

            return [
                'transcript' => $transcript,
                'path' => $transcriptPath,
                'status' => $transcript->isEmpty() ? 'empty' : 'available',
            ];
        } catch (\Throwable) {
            return [
                'transcript' => null,
                'path' => $transcriptPath,
                'status' => 'unavailable',
            ];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function followingSections(ServiceSection $section): array
    {
        $sectionOrder = (int) $section->section_order;
        $sectionId = (int) $section->id;

        $followingSections = $section->processingLog
            ->serviceSections()
            ->where(function (Builder $query) use ($sectionOrder, $sectionId): void {
                $query
                    ->where('section_order', '>', $sectionOrder)
                    ->orWhere(function (Builder $query) use ($sectionOrder, $sectionId): void {
                        $query
                            ->where('section_order', $sectionOrder)
                            ->where('id', '>', $sectionId);
                    });
            })
            ->orderBy('section_order')
            ->orderBy('id')
            ->limit(self::MAX_FOLLOWING_SECTIONS)
            ->get()
            ->map(function (ServiceSection $following) use ($section): array {
                $sectionStart = (float) $section->start_time;
                $sectionEnd = (float) $section->end_time;
                $followingStart = (float) $following->start_time;
                $followingEnd = (float) $following->end_time;
                $overlap = max(0.0, min($sectionEnd, $followingEnd) - max($sectionStart, $followingStart));
                $gap = max(0.0, $followingStart - $sectionEnd);

                return [
                    'id' => (int) $following->id,
                    'section_order' => (int) $following->section_order,
                    'section_type' => $following->section_type->value,
                    'title' => $following->title,
                    'start_time' => $followingStart,
                    'end_time' => $followingEnd,
                    'duration' => max(0.0, $followingEnd - $followingStart),
                    'relationship' => $overlap > 0.0
                        ? 'overlaps_candidate'
                        : ($gap > 0.0 ? 'after_candidate' : 'touches_candidate'),
                    'overlap_seconds' => $overlap,
                    'gap_seconds' => $gap,
                ];
            })
            ->values()
            ->all();

        return array_values($followingSections);
    }

    /**
     * @return array<string, mixed>
     */
    private function tailEvidence(
        ?ChurchServiceTranscript $transcript,
        string $transcriptStatus,
        float $start,
        float $end,
    ): array {
        $windowStart = max($start, $end - self::TAIL_EVIDENCE_WINDOW_SECONDS);
        $base = [
            'basis' => 'timed_service_transcript_tail',
            'candidate_end_time' => $end,
            'window_start' => $windowStart,
            'window_end' => $end,
            'window_seconds' => self::TAIL_EVIDENCE_WINDOW_SECONDS,
            'transcript_status' => $transcriptStatus,
        ];

        if (! $transcript instanceof ChurchServiceTranscript) {
            return [
                ...$base,
                'status' => 'unavailable',
                'cue_count' => 0,
                'omitted_cue_count' => 0,
                'cues' => [],
                'text' => null,
            ];
        }

        $cues = array_values(array_filter(
            $transcript->cues,
            static fn (array $cue): bool => $cue['end'] > $windowStart && $cue['start'] < $end,
        ));
        $recordedCues = array_slice($cues, -self::MAX_RECORDED_TAIL_CUES);

        return [
            ...$base,
            'status' => $cues === [] ? 'no_cues' : 'available',
            'cue_count' => count($cues),
            'omitted_cue_count' => max(0, count($cues) - count($recordedCues)),
            'cues' => $recordedCues,
            'text' => $recordedCues === [] ? null : implode(' ', array_column($recordedCues, 'text')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewedRecuts(ServiceSection $section): array
    {
        $value = $section->metadata?->toArray()[self::METADATA_KEY]['reviewed_recuts'] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
