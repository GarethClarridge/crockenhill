<?php

declare(strict_types=1);

namespace App\Services\ChurchService\ReviewInventory;

use App\Models\ServiceSection;
use JsonException;
use RuntimeException;

/**
 * What applying built-but-unapplied work would change, read from a read-only final-code
 * replay (video plan §4.1a step 2; rows written by the replay script, one JSON object
 * per run). A section the replay shows changing is named with the fix that changes it;
 * a section whose output could not be planned is named with the input it lacks.
 */
final class PendingApplication
{
    public const RECOMPOSE = 'recompose: structure changes under current rules (split-song/music-window snap)';

    public const SERMON_RECUT = 're-extract sermon: its cut changes (K18 introduction, K19/start fixes)';

    public const SECTION_RECUT = 're-cut section: its candidate cut changes';

    public const MISSING_EDGE_WORDS = 'edge word timings / K16 answer under the current processing signature (next round edge step)';

    /**
     * @param  array<int, string>  $sectionFixes  section id => fix that changes its own cut
     * @param  array<int, list<array{0: float, 1: float}>>  $structureChanges  run id => changed time ranges
     * @param  array<int, list<int>>  $sermonChanges  run id => sermon-plan section ids whose cut changes
     * @param  array<int, true>  $unassessableSections  section id => its own cut could not be planned
     * @param  array<int, list<int>>  $sermonUnassessable  run id => sermon-plan section ids whose end is unassessed
     * @param  array<int, true>  $replayedRuns  run id => replayed
     * @param  array<int, array{section_recuts?: list<int>, unassessable_sections?: list<int>}>  $sectionsByRun  run id => its sections' effects
     */
    public function __construct(
        private readonly array $sectionFixes = [],
        private readonly array $structureChanges = [],
        private readonly array $sermonChanges = [],
        private readonly array $unassessableSections = [],
        private readonly array $sermonUnassessable = [],
        private readonly array $replayedRuns = [],
        private readonly array $sectionsByRun = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /** Every `rows*.jsonl` file in a replay directory. */
    public static function fromReplayDirectory(string $directory): self
    {
        $paths = glob(rtrim($directory, '/').'/rows*.jsonl') ?: [];
        if ($paths === []) {
            throw new RuntimeException("No replay rows in {$directory}.");
        }

        $rows = [];
        foreach ($paths as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                try {
                    $rows[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException("Unreadable replay row in {$path}: {$exception->getMessage()}");
                }
            }
        }

        return self::fromRows($rows);
    }

    /** @param iterable<array<string, mixed>> $rows */
    public static function fromRows(iterable $rows): self
    {
        $sectionFixes = [];
        $structureChanges = [];
        $sermonChanges = [];
        $unassessableSections = [];
        $sermonUnassessable = [];
        $replayedRuns = [];
        $sectionsByRun = [];

        foreach ($rows as $row) {
            if (isset($row['skipped']) || isset($row['error']) || ! is_int($row['run'] ?? null)) {
                continue;
            }
            $run = $row['run'];
            $replayedRuns[$run] = true;

            foreach ($row['structure']['diffs'] ?? [] as $diff) {
                $times = array_filter([
                    $diff['stored']['start_time'] ?? null, $diff['stored']['end_time'] ?? null,
                    $diff['replayed']['start_time'] ?? null, $diff['replayed']['end_time'] ?? null,
                ], is_numeric(...));
                if ($times !== []) {
                    $structureChanges[$run][] = [(float) min($times), (float) max($times)];
                }
            }

            $sermon = $row['sermon'] ?? null;
            if (is_array($sermon) && ! isset($sermon['error'])) {
                $selected = array_values(array_unique(array_merge($sermon['recorded_selected'] ?? [], $sermon['replayed_selected'] ?? [])));
                if (isset($sermon['unassessable'])) {
                    $sermonUnassessable[$run] = $selected;
                    if (self::startsMoved($sermon['recorded_segments'] ?? [], $sermon['without_end_segments'] ?? null)) {
                        $sermonChanges[$run] = $selected;
                    }
                } elseif (($sermon['same_cut'] ?? true) === false
                    || (($sermon['replayed_segments'] ?? []) === [] && ($sermon['recorded_segments'] ?? []) !== [])) {
                    $sermonChanges[$run] = $selected;
                }
            }

            foreach ($row['sections'] ?? [] as $section) {
                if (isset($section['unassessable'])) {
                    $unassessableSections[$section['id']] = true;
                    $sectionsByRun[$run]['unassessable_sections'][] = (int) $section['id'];
                } elseif (($section['same_cut'] ?? null) === false) {
                    $sectionFixes[$section['id']] = self::SECTION_RECUT;
                    $sectionsByRun[$run]['section_recuts'][] = (int) $section['id'];
                }
            }
        }

        return new self($sectionFixes, $structureChanges, $sermonChanges, $unassessableSections, $sermonUnassessable, $replayedRuns, $sectionsByRun);
    }

    /**
     * The operations applying built work needs, per run the replay shows changing or lacking
     * evidence (§4.1a step 3). A hand-edited structure (X7) is never recomposed: its sections
     * are re-extracted as they stand.
     *
     * @param  list<int>  $handEditedRuns
     * @return array<int, list<string>>
     */
    public function routes(array $handEditedRuns): array
    {
        $runs = array_unique([...array_keys($this->structureChanges), ...array_keys($this->sermonChanges),
            ...array_keys($this->sermonUnassessable), ...array_keys($this->sectionsByRun)]);
        sort($runs);
        $routes = [];
        foreach ($runs as $run) {
            $operations = [];
            if (isset($this->structureChanges[$run])) {
                $operations[] = in_array($run, $handEditedRuns, true)
                    ? 'X7: hand-edited structure, never recomposed; re-extract as it stands'
                    : 'recompose (structure changes)';
            }
            if (isset($this->sermonChanges[$run])) {
                $operations[] = 're-extract sermon (cut changes)';
            }
            if (($this->sectionsByRun[$run]['section_recuts'] ?? []) !== []) {
                $operations[] = 're-cut sections '.implode(', ', $this->sectionsByRun[$run]['section_recuts']);
            }
            if (isset($this->sermonUnassessable[$run]) || ($this->sectionsByRun[$run]['unassessable_sections'] ?? []) !== []) {
                $operations[] = 'edge step first: '.self::MISSING_EDGE_WORDS;
            }
            if ($operations !== []) {
                $routes[$run] = $operations;
            }
        }

        return $routes;
    }

    public function replayed(int $runId): bool
    {
        return isset($this->replayedRuns[$runId]);
    }

    /** The built fix that changes this section on application, or null. */
    public function fixFor(ServiceSection $section): ?string
    {
        if (isset($this->sectionFixes[$section->id])) {
            return $this->sectionFixes[$section->id];
        }
        if (in_array($section->id, $this->sermonChanges[$section->media_processing_log_id] ?? [], true)) {
            return self::SERMON_RECUT;
        }
        foreach ($this->structureChanges[$section->media_processing_log_id] ?? [] as [$from, $to]) {
            if ((float) $section->start_time < $to && (float) $section->end_time > $from) {
                return self::RECOMPOSE;
            }
        }

        return null;
    }

    /** The input this section's output plan lacks, or null when it was planned. */
    public function missingInputFor(ServiceSection $section): ?string
    {
        return isset($this->unassessableSections[$section->id])
            || in_array($section->id, $this->sermonUnassessable[$section->media_processing_log_id] ?? [], true)
            ? self::MISSING_EDGE_WORDS
            : null;
    }

    /**
     * Whether a start moved between the recorded cut and the replan without the sermon end.
     * Ends are not compared: without the end answer the planner also skips K16's inner kept lines.
     *
     * @param  list<array{start_time: float, end_time: float}>  $recorded
     * @param  list<array{start_time: float, end_time: float}>|null  $replanned
     */
    private static function startsMoved(array $recorded, ?array $replanned): bool
    {
        if ($replanned === null) {
            return false;
        }
        if (count($recorded) !== count($replanned)) {
            return true;
        }

        return array_any($recorded, static fn (array $span, int $index): bool => abs($span['start_time'] - $replanned[$index]['start_time']) >= 0.01);
    }
}
