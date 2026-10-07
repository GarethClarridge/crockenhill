<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Lists every song or sermon edge a cut plan refuses to execute, so the operator can hear it
 * before deciding (Codex review of the canary 13 fixes, 2026-10-06): an unresolved edge blocks
 * its clip, and a blocked clip has no media to listen to. Each edge is one of two kinds:
 *
 * - `missing_edge_evidence`: the plan needs edge words not yet decoded. Tier C's edge step decodes
 *   them; nothing is asked.
 * - `needs_operator`: the evidence could not place the edge (a song end whose speech onset is not
 *   established, a sermon end mid-thought, an ambiguous anchor). Excerpts of the recorded service
 *   audio around the proposed cut are written beside it, never extracted or published media.
 *
 * Reads only: no plan, section, ruling or media is written.
 *
 * Deletion trigger: delete once unresolved edges can be heard and answered on the service screen.
 */
class ExportUnresolvedEdgeExcerptsCommand extends Command
{
    protected $signature = 'historic-import:unresolved-edge-excerpts
        {runs* : Processing log ids}
        {--out= : Directory for excerpts.json and clips/ (default storage/scratch/unresolved-edges-<time>)}';

    protected $description = 'List the edges cut plans refuse, with source-audio excerpts for those an operator must decide, read-only';

    /** Seconds heard before the proposed cut, as the clip would end. */
    private const LEAD_SECONDS = 8.0;

    /** Seconds heard either side of the proposed cut. */
    private const AROUND_SECONDS = 6.0;

    public function handle(CueSafeExtractionPlan $plans, OutputEdgeWordTimings $evidence): int
    {
        $out = $this->outputDirectory();

        if (! is_dir("{$out}/clips") && ! mkdir("{$out}/clips", 0755, true) && ! is_dir("{$out}/clips")) {
            throw new RuntimeException("Cannot create {$out}/clips.");
        }

        $entries = [];

        foreach ((array) $this->argument('runs') as $id) {
            $log = MediaProcessingLog::query()->find((int) $id);

            if (! $log instanceof MediaProcessingLog) {
                $this->error("Run {$id} not found.");

                continue;
            }

            foreach ($this->outputs($log) as [$label, $plan]) {
                try {
                    $planned = $plan();
                } catch (OutputEdgeTimingsMissing $exception) {
                    $entries[] = ['run' => $log->id, 'output' => $label, 'kind' => 'missing_edge_evidence', 'detail' => $exception->getMessage()];

                    continue;
                }

                foreach (CueSafeExtractionPlan::unresolvedEdges($planned['cue_edge_widening']) as $edge) {
                    $audit = collect($planned['cue_edge_widening'])->first(static fn (array $entry): bool => $entry['span_index'] === $edge['span_index'] && $entry['edge'] === $edge['edge']
                        && in_array($entry['reason'] ?? null, [CueSafeExtractionPlan::AMBIGUOUS_CUE_ANCHOR, CueSafeExtractionPlan::SONG_END_UNRESOLVED, CueSafeExtractionPlan::SPOKEN_END_UNRESOLVED], true));
                    $entries[] = $this->decision($log, $label, $edge['edge'], (float) ($audit['time'] ?? $edge['original_time']), (array) $audit, $evidence->cues($log));
                }
            }
        }

        $this->excerpts($entries, $out);
        file_put_contents("{$out}/excerpts.json", json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->table(['Run', 'Output', 'Kind', 'Detail'], array_map(static fn (array $entry): array => [
            $entry['run'], $entry['output'], $entry['kind'], $entry['kind'] === 'needs_operator' ? "{$entry['edge']} at {$entry['time']}: {$entry['reason']}" : $entry['detail'],
        ], $entries));
        $this->info(sprintf('%d need an operator, %d need the edge step. Written to %s; nothing applied.',
            count(array_filter($entries, static fn (array $entry): bool => $entry['kind'] === 'needs_operator')),
            count(array_filter($entries, static fn (array $entry): bool => $entry['kind'] === 'missing_edge_evidence')),
            $out,
        ));

        return self::SUCCESS;
    }

    /**
     * Each output the run's plans cut: every section cut as a clip of its own (songs, short talks)
     * and its sermon, as the extraction steps plan them.
     *
     * @return list<array{0: string, 1: \Closure(): array{segments: list<array{start_time: float, end_time: float}>, cue_edge_widening: list<array<string, mixed>>}}>
     */
    private function outputs(MediaProcessingLog $log): array
    {
        $plans = app(CueSafeExtractionPlan::class);
        $handlers = app(SectionPublicationHandlerFactory::class);
        $outputs = [];

        foreach ($log->serviceSections()->orderBy('start_time')->get() as $section) {
            if ($handlers->forSection($section) !== null) {
                $outputs[] = [str_replace('_', ' ', $section->section_type->value)." §{$section->id}", static fn (): array => $plans->forSection($section)];
            }
        }

        $selected = $log->processing_metadata?->raw['sermon_composition']['selected_section_ids'] ?? [];
        $spans = array_values($log->serviceSections()->whereIn('id', is_array($selected) ? $selected : [])->orderBy('start_time')->get()
            ->map(static fn (ServiceSection $section): array => ['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time])->all());

        if ($spans !== []) {
            $outputs[] = ['sermon', static fn (): array => $plans->forSpans($log, $spans, sermonEnd: true)];
        }

        return $outputs;
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return array<string, mixed>
     */
    private function decision(MediaProcessingLog $log, string $output, string $edge, float $time, array $audit, array $cues): array
    {
        $id = sprintf('%d-%s-%s-%d', $log->id, preg_replace('/[^a-z0-9]+/', '', strtolower($output)), $edge, (int) round($time * 100));
        $clip = static fn (string $name, float $from, float $to, string $label): array => ['src' => "clips/{$id}-{$name}.mp3", 'from' => max(0.0, $from), 'to' => $to, 'label' => $label,
            'cues' => array_values(array_map(static fn (array $cue): array => ['t' => $cue['start'], 'x' => $cue['text']],
                array_filter($cues, static fn (array $cue): bool => $cue['end'] > $from && $cue['start'] < $to)))];

        return [
            'run' => $log->id, 'output' => $output, 'kind' => 'needs_operator', 'id' => $id, 'edge' => $edge, 'time' => round($time, 2),
            'reason' => (string) ($audit['reason'] ?? ''), 'original_time' => $audit['original_time'] ?? null, 'onset_bound' => $audit['onset_bound'] ?? null,
            'key' => CueSafeExtractionPlan::edgeAnswerKey($audit),
            'choices' => ['right' => 'The cut is right', 'cut_at' => 'Cut at the time given (seconds, service time)', 'defer' => 'Can’t tell'],
            'clips' => [
                $clip('as', $edge === 'end' ? $time - self::LEAD_SECONDS : $time, $edge === 'end' ? $time : $time + self::LEAD_SECONDS, $edge === 'end' ? 'As it would end' : 'As it would open'),
                $clip('around', $time - self::AROUND_SECONDS, $time + self::AROUND_SECONDS, 'Around the proposed cut'),
            ],
            'audio' => $this->audio($log),
        ];
    }

    /** @param list<array<string, mixed>> $entries */
    private function excerpts(array $entries, string $out): void
    {
        foreach ($entries as $entry) {
            if ($entry['kind'] !== 'needs_operator' || $entry['audio'] === null) {
                continue;
            }

            foreach ($entry['clips'] as $clip) {
                $result = Process::timeout(300)->run([
                    (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg'),
                    '-v', 'error', '-y', '-ss', (string) $clip['from'], '-to', (string) $clip['to'],
                    '-i', $entry['audio'], '-ac', '1', '-b:a', '32k', "{$out}/{$clip['src']}",
                ]);

                if (! $result->successful()) {
                    $this->error("Clip {$clip['src']} failed: {$result->errorOutput()}");
                }
            }
        }
    }

    /** The run's latest recorded service audio, or null when there is none to excerpt. */
    private function audio(MediaProcessingLog $log): ?string
    {
        $audio = array_values(array_filter(ServiceArtifactStorage::recordedFor($log), static fn (array $entry): bool => $entry['kind'] === 'audio'));

        return $audio === [] ? null : Storage::disk(end($audio)['disk'])->path(end($audio)['path']);
    }

    private function outputDirectory(): string
    {
        $out = $this->option('out');

        return is_string($out) && $out !== '' ? rtrim($out, '/') : storage_path('scratch/unresolved-edges-'.now()->format('Ymd-His'));
    }
}
