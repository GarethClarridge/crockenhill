<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\ChurchService\ServiceSectionSyncService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Clear song clips left on sections that are no longer songs (plan §4.1, archived follow-up round §9.1).
 *
 * Re-detections re-typed rows that carried song clips, and the rows kept the clip's path and its
 * `SongVideo` binding: run 1002's prayer and sermon claimed songs 859 and 159 (2026-10-14). Section
 * sync no longer makes these (R9); this clears the rows it left. Each section's path is un-pointed;
 * each clip moves to the run's one section of its song when that section has no clip of its own,
 * and is otherwise retired as sync retires one. No file is deleted: quarantine is single-copy, and
 * deleting a clip is the operator's decision from the report.
 *
 * Writes to round members, so it runs only when no round is open (runbook §2.4).
 */
class ClearStaleSongClipsCommand extends Command
{
    protected $signature = 'historic-import:clear-stale-song-clips
                            {--run=* : Restrict to these processing log IDs}
                            {--execute : Make the changes (default: dry run)}
                            {--report= : Write a JSON report to this path on the local disk}';

    protected $description = 'Un-point song clips left on sections that are not songs, re-binding or retiring each clip';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        if (! $execute) {
            $this->warn('DRY RUN: nothing will be written.');
        }

        $report = ['execute' => $execute, 'generated_at' => now()->toIso8601String(), 'runs' => [], 'skipped' => []];
        $sections = 0;
        $outcomes = [];

        foreach ($this->staleSections() as $section) {
            // An approved or published section's paths are its release; the schema requires them.
            if (in_array($section->publication_status, [ServiceSectionPublicationStatus::Approved, ServiceSectionPublicationStatus::Published], true)) {
                $report['skipped'][] = ['section_id' => $section->id, 'reason' => $section->publication_status->value];
                $this->line("Section {$section->id} is {$section->publication_status->value}; left alone.");

                continue;
            }

            $entry = DB::transaction(fn (): array => $this->clear($section, $execute));
            $report['runs'][(string) $section->media_processing_log_id][] = $entry;
            $sections++;

            foreach ($entry['song_videos'] as $video) {
                $outcomes[$video['outcome']] = ($outcomes[$video['outcome']] ?? 0) + 1;
            }
        }

        $this->info(sprintf(
            '%s %d section(s) in %d run(s): %d clip(s) re-bound, %d retired; %d skipped.',
            $execute ? 'Cleared' : 'Would clear',
            $sections,
            count($report['runs']),
            $outcomes['rebound'] ?? 0,
            $outcomes['retired'] ?? 0,
            count($report['skipped']),
        ));

        if (is_string($this->option('report')) && $this->option('report') !== '') {
            Storage::disk('local')->put($this->option('report'), json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
            $this->line('Report written to '.$this->option('report'));
        }

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, ServiceSection>
     */
    private function staleSections(): iterable
    {
        return ServiceSection::query()
            ->where('section_type', '!=', ServiceSectionType::Song->value)
            ->where(fn (Builder $query): Builder => $query
                ->where('extracted_video_path', 'like', 'sermons/songs/%')
                ->orWhereHas('songVideos'))
            ->when($this->option('run') !== [], fn (Builder $query): Builder => $query
                ->whereIn('media_processing_log_id', array_map('intval', (array) $this->option('run'))))
            ->orderBy('media_processing_log_id')
            ->orderBy('id')
            ->with('songVideos')
            ->lazyById();
    }

    /**
     * @return array{section_id: int, section_type: string, cleared_path: string|null, song_videos: list<array{song_video_id: int, song_id: int|null, outcome: string, section_id: int|null}>}
     */
    private function clear(ServiceSection $section, bool $execute): array
    {
        $path = str_starts_with((string) $section->extracted_video_path, 'sermons/songs/') ? $section->extracted_video_path : null;
        $videos = [];

        foreach ($section->songVideos as $video) {
            $target = $this->songSectionFor($section, $video);
            $videos[] = [
                'song_video_id' => $video->id,
                'song_id' => $video->song_id,
                'outcome' => $target instanceof ServiceSection ? 'rebound' : 'retired',
                'section_id' => $target?->id,
            ];

            if ($execute) {
                $video->forceFill($target instanceof ServiceSection
                    ? ['service_section_id' => $target->id]
                    : ['service_section_id' => null, 'publication_state' => SermonPublicationState::Quarantined])->save();
            }
        }

        if ($execute && $path !== null) {
            $section->forceFill(['extracted_video_path' => null])->save();
        }

        return [
            'section_id' => $section->id,
            'section_type' => $section->section_type->value,
            'cleared_path' => $path,
            'song_videos' => $videos,
        ];
    }

    /**
     * The run's one song section for this clip's song, when it has no clip of its own; a section
     * that already has one makes this clip a stale duplicate, as {@see ServiceSectionSyncService} treats it.
     */
    private function songSectionFor(ServiceSection $section, SongVideo $video): ?ServiceSection
    {
        $candidates = ServiceSection::query()
            ->where('media_processing_log_id', $section->media_processing_log_id)
            ->where('section_type', ServiceSectionType::Song->value)
            ->with('churchServiceItem')
            ->get()
            ->filter(static fn (ServiceSection $song): bool => $song->resolvedSongId() === $video->song_id)
            ->values();

        $only = $candidates->count() === 1 ? $candidates->first() : null;

        return $only instanceof ServiceSection && ! $only->songVideos()->exists() ? $only : null;
    }
}
