<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use Illuminate\Support\Facades\Storage;

/**
 * One service's evidence for the post-round audit (audit plan §1, §3), read without writing:
 * the source transcript, and apart from it what was delivered — each recorded output with its
 * executed segments and their place in the output's own clock, the output's stored transcript,
 * sections, song bindings and order-of-service items. A reviewer reads the source first and the
 * delivered part second, so the two are kept apart.
 *
 * Built from what every run records (not historic staging), so the same packet can check weekly
 * runs. Missing evidence is listed in `gaps` and makes the packet unassessable for that part;
 * it is never filled from a prediction: segments are the executed ones on record, never section
 * bounds or a new plan.
 */
class ServiceAuditPacket
{
    /** Source lines shown at each end of a segment, where joins and edges are judged. */
    private const EDGE_LINES = 3;

    public function __construct(private readonly HistoricStagingContextRegistry $registry) {}

    /** @return array{source: array<string, mixed>, delivered: array<string, mixed>, gaps: list<string>} */
    public function build(MediaProcessingLog $run): array
    {
        $context = $run->historicStagingContext();

        return $context === null ? $this->assemble($run) : $this->registry->within($context, fn (): array => $this->assemble($run));
    }

    /** @return array{source: array<string, mixed>, delivered: array<string, mixed>, gaps: list<string>} */
    private function assemble(MediaProcessingLog $run): array
    {
        $gaps = [];
        $service = $run->churchService;
        $raw = $run->storedServiceTranscriptContents();
        $cues = [];
        if ($raw === null) {
            $gaps[] = 'source_transcript_missing';
        } else {
            $cues = ChurchServiceTranscript::fromArray(json_decode($raw, true, flags: JSON_THROW_ON_ERROR))->cues;
        }
        $source = [
            'run' => $run->id,
            'service' => $service === null ? null : ['id' => $service->id, 'date' => $service->date->toDateString(), 'service' => $service->service],
            'duration' => $run->duration === null ? null : (float) $run->duration,
            'transcript_sha256' => $raw === null ? null : hash('sha256', $raw),
            'unobservable_windows' => $run->serviceTranscriptUnobservableWindows(),
            'cues' => array_map(static fn (array $cue): array => ['start' => (float) $cue['start'], 'end' => (float) $cue['end'], 'text' => (string) $cue['text']], $cues),
        ];

        $metadata = $run->processing_metadata?->toArray() ?? [];
        $recorded = is_array($metadata['media_outputs'] ?? null) ? $metadata['media_outputs'] : [];
        $sections = $run->serviceSections()->orderBy('start_time')->get();
        $outputs = [];
        foreach (Sermon::query()->where('livestream_processing_id', $run->processing_id)->orderBy('id')->get() as $sermon) {
            $outputs[] = $this->output('sermon', $recorded['sermon'] ?? null, $run, $cues, $gaps) + ['sermon' => [
                'id' => $sermon->id, 'content_type' => $sermon->content_type, 'title' => $sermon->title, 'reference' => $sermon->reference,
                'preacher' => $sermon->preacher, 'series' => $sermon->series, 'summary' => $sermon->summary, 'points' => $sermon->points,
                'partial_recording' => (bool) $sermon->partial_recording, 'publication_state' => $sermon->publication_state,
                'video_file_path' => $sermon->video_file_path, 'transcript' => $this->sermonTranscript($sermon, $gaps),
            ]];
        }
        foreach ($sections as $section) {
            if (isset($recorded['section_'.$section->id])) {
                $outputs[] = $this->output('section_'.$section->id, $this->sectionMedia($section, $recorded['section_'.$section->id]), $run, $cues, $gaps) + ['section_id' => $section->id];
            }
        }
        $byFile = [];
        foreach ($outputs as $output) {
            if (is_string($output['sha256'] ?? null)) {
                $byFile[$output['sha256']][] = $output['key'];
            }
        }
        foreach ($byFile as $keys) {
            if (count($keys) > 1) {
                $gaps[] = 'duplicate_output: '.implode(', ', $keys);
            }
        }

        $delivered = [
            'run' => $run->id,
            'processing_id' => $run->processing_id,
            'status' => $run->status->value,
            'sections' => $sections->map(static fn (ServiceSection $section): array => [
                'id' => $section->id, 'type' => $section->section_type->value, 'title' => $section->title,
                'start' => (float) $section->start_time, 'end' => (float) $section->end_time, 'status' => $section->status,
                'publication_status' => $section->publication_status, 'needs_manual_review' => (bool) $section->needs_manual_review,
                'summary' => $section->summary, 'song_match_type' => $section->song_match_type,
                'song_videos' => SongVideo::query()->where('service_section_id', $section->id)->with('song:id,title')->get()
                    ->map(static fn (SongVideo $video): array => ['id' => $video->id, 'song_id' => $video->song_id, 'song' => $video->song->title, 'publication_state' => $video->publication_state])->all(),
            ])->values()->all(),
            'outputs' => $outputs,
            'order_of_service' => $service === null ? [] : ChurchServiceItem::query()->where('church_service_id', $service->id)->orderBy('position')->get()
                ->map(static fn (ChurchServiceItem $item): array => ['position' => $item->position, 'type' => $item->type, 'title' => $item->title, 'source' => $item->source, 'section_id' => $item->livestream_service_section_id])->all(),
        ];

        return ['source' => $source, 'delivered' => $delivered, 'gaps' => array_values(array_unique($gaps))];
    }

    /**
     * A recorded output and where each executed segment sits on the output's own clock, with the
     * source lines at each segment's edges (the source file holds the rest). Those lines share the source transcript's ancestry, so they
     * are no independent account of the output.
     *
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<string>  $gaps
     * @return array<string, mixed>
     */
    private function output(string $key, mixed $media, MediaProcessingLog $run, array $cues, array &$gaps): array
    {
        $segments = is_array($media) ? ($media['provenance']['segments'] ?? null) : null;
        if (! is_array($media) || ! is_array($segments) || $segments === []) {
            $gaps[] = "{$key}_segments_missing";

            return ['key' => $key, 'disk' => $media['disk'] ?? null, 'path' => $media['path'] ?? null,
                'sha256' => is_array($media) ? ($media['sha256'] ?? null) : null, 'segments' => []];
        }
        if (($media['provenance']['processing_id'] ?? null) !== $run->processing_id) {
            $gaps[] = "{$key}_recorded_for_another_run";
        }
        $mapped = [];
        $offset = 0.0;
        foreach ($segments as $segment) {
            $start = (float) $segment['start_time'];
            $end = (float) $segment['end_time'];
            $held = array_values(array_filter($cues, static fn (array $cue): bool => $cue['end'] > $start && $cue['start'] < $end));
            $mapped[] = [
                'source_start' => $start, 'source_end' => $end, 'output_start' => round($offset, 3), 'output_end' => round($offset + $end - $start, 3),
                'lines' => count($held), 'first_lines' => array_slice($held, 0, self::EDGE_LINES), 'last_lines' => array_slice($held, -self::EDGE_LINES),
            ];
            $offset += $end - $start;
        }

        return ['key' => $key, 'disk' => $media['disk'] ?? null, 'path' => $media['path'] ?? null, 'sha256' => $media['sha256'] ?? null,
            'duration' => round($offset, 3), 'segments' => $mapped];
    }

    /**
     * A section's media_outputs entry carries the run's provenance, whose segments are the sermon's
     * trim, and the disk it was first written to; the section's own cut is the one its extraction
     * recorded, and its file is where the section says it is now (a promoted clip moves disk).
     *
     * @param  array<string, mixed>  $media
     * @return array<string, mixed>
     */
    private function sectionMedia(ServiceSection $section, array $media): array
    {
        $extraction = $section->metadata?->raw['publication_candidate_extraction'] ?? null;
        $media['provenance']['segments'] = is_array($extraction) ? ($extraction['segments'] ?? null) : null;
        if (filled($section->extracted_video_path)) {
            $media['disk'] = $section->extractedAssetDisk();
            $media['path'] = $section->extracted_video_path;
        }

        return $media;
    }

    /** @param list<string> $gaps */
    private function sermonTranscript(Sermon $sermon, array &$gaps): ?string
    {
        $path = $sermon->transcript_file_path;
        if (! is_string($path) || $path === '' || ! is_string($sermon->asset_disk)) {
            $gaps[] = "sermon_{$sermon->id}_transcript_missing";

            return null;
        }
        try {
            $text = Storage::disk($sermon->asset_disk)->get($path);
        } catch (\Throwable) {
            $text = null;
        }
        if (! is_string($text)) {
            $gaps[] = "sermon_{$sermon->id}_transcript_unreadable";
        }

        return is_string($text) ? $text : null;
    }
}
