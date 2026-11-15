<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PreacherSource;
use App\Enums\SermonTitleProvenance;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\Scripture\SermonScriptureFilterIndexService;
use App\Services\Sermon\SermonSeriesCorroboration;
use App\Services\Sermon\SermonSlugGenerator;
use App\Support\SeedSignature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove what `db:seed` left in the local database, and replace sample values on
 * the real recordings that were attached to seeded sermon rows.
 *
 * Deletion trigger: delete this command when the seed signature finds 0 rows
 * locally and the last round has been exported.
 *
 * Dry run by default; `--execute` writes everything in one transaction. Sermons
 * with a real run keep their row: only fields still equal to the seeded value
 * change, taking title, reference, series and points from the run's stored
 * analysis (the pipeline's own output) and the slug from the new title. Seeded
 * sermons with no real run, the seeded sample run and its sections, the seeded
 * service item, preachers that only the seeder creates and the `guest speaker`
 * alias are deleted. Any row with a reference this command does not expect makes
 * it refuse and write nothing.
 */
class ClearSeededResidueCommand extends Command
{
    protected $signature = 'sermons:clear-seeded-residue
                            {--execute : Write the changes (the default is a dry run)}';

    protected $description = 'Clear seeded sample sermons, run, service item, preachers and alias from the local database';

    /** @var list<array{string, string, string, string, string}> */
    private array $lines = [];

    /** @var list<string> */
    private array $refusals = [];

    /** @var list<string> */
    private array $unresolved = [];

    /** @var list<\Closure(): void> */
    private array $actions = [];

    public function handle(SermonSlugGenerator $slugs): int
    {
        $execute = (bool) $this->option('execute');
        $this->lines = $this->refusals = $this->unresolved = $this->actions = [];

        $this->planSermons($slugs);
        $this->planSeedRun();
        $this->planServiceItems();
        $this->planPreachers();

        if ($this->refusals !== []) {
            $this->error('Refusing to continue; nothing was written:');
            foreach ($this->refusals as $refusal) {
                $this->line(' - '.$refusal);
            }

            return self::FAILURE;
        }

        if ($this->lines === [] && $this->unresolved === []) {
            $this->info('Nothing to do: the seed signature finds no rows.');

            return self::SUCCESS;
        }

        $this->info($execute ? 'EXECUTING.' : 'DRY RUN: no database changes will be made.');
        $this->table(['Table', 'Row', 'Field', 'Before', 'After'], $this->lines);

        foreach ($this->unresolved as $item) {
            $this->warn('Unresolved: '.$item);
        }

        if (! $execute) {
            $this->info(sprintf('Would change %d value(s)/row(s); %d unresolved.', count($this->lines), count($this->unresolved)));

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            foreach ($this->actions as $action) {
                $action();
            }
        });

        $this->info(sprintf('Changed %d value(s)/row(s); %d unresolved.', count($this->lines), count($this->unresolved)));

        return $this->unresolved === [] ? self::SUCCESS : self::FAILURE;
    }

    private function planSermons(SermonSlugGenerator $slugs): void
    {
        $candidates = Sermon::query()
            ->whereIn('date', SeedSignature::seededDates())
            ->orderBy('id')
            ->get()
            ->filter(fn (Sermon $sermon): bool => SeedSignature::matchesSermon($sermon) !== []);

        foreach ($candidates as $sermon) {
            $run = $this->realRun($sermon);

            if ($run instanceof MediaProcessingLog) {
                $this->planAttachedSermon($sermon, $run, $slugs);

                continue;
            }

            $this->planUnattachedSermon($sermon);
        }
    }

    private function realRun(Sermon $sermon): ?MediaProcessingLog
    {
        $query = MediaProcessingLog::query()
            ->where('processing_id', '!=', SeedSignature::PROCESSING_ID)
            ->where(function ($inner) use ($sermon): void {
                $inner->where('sermon_id', $sermon->id);

                if (filled($sermon->livestream_processing_id)) {
                    $inner->orWhere('processing_id', $sermon->livestream_processing_id);
                }
            });

        return $query
            ->orderByRaw('processing_id = ? desc', [(string) $sermon->livestream_processing_id])
            ->orderByDesc('id')
            ->first();
    }

    private function planAttachedSermon(Sermon $sermon, MediaProcessingLog $run, SermonSlugGenerator $slugs): void
    {
        $service = (string) $sermon->service?->value;
        $label = "sermon {$sermon->id} ({$sermon->date->toDateString()} {$service}, run {$run->id})";
        $seeded = SeedSignature::matchesSermon($sermon);
        $analysis = $run->ai_analysis;
        $updates = [];

        if (isset($seeded['title'])) {
            $title = is_string($analysis?->title) ? trim($analysis->title) : '';

            if ($title !== '') {
                $updates['title'] = $title;
                $updates['title_provenance'] = SermonTitleProvenance::AiAnalysis;
            } else {
                $this->unresolved[] = "{$label}: title '{$sermon->title}' is seeded and run {$run->id} stored no analysis title.";
            }
        }

        if (isset($seeded['slug'])) {
            $base = $updates['title'] ?? (isset($seeded['title']) ? null : $sermon->title);

            if (is_string($base) && $base !== '') {
                $updates['slug'] = $slugs->generate($base, $sermon->id);
            } else {
                $this->unresolved[] = "{$label}: slug '{$sermon->slug}' is seeded and no real title is available to derive it from.";
            }
        }

        if (isset($seeded['reference'])) {
            $reference = is_string($analysis?->reference) ? trim($analysis->reference) : '';

            if ($reference !== '') {
                $updates['reference'] = $reference;
                $updates['scripture_passage_id'] = null;
            } else {
                $this->unresolved[] = "{$label}: reference '{$sermon->reference}' is seeded and run {$run->id} stored no analysis reference.";
            }
        }

        if (isset($seeded['series'])) {
            $series = is_string($analysis?->series) && trim($analysis->series) !== '' ? trim($analysis->series) : null;
            $reference = (string) ($updates['reference'] ?? $sermon->reference);
            $updates['series'] = $series !== null && app(SermonSeriesCorroboration::class)->corroborates($series, $reference) ? $series : null;
        }

        if (isset($seeded['points'])) {
            $points = $analysis === null ? [] : $analysis->points;
            $updates['points'] = $points === [] ? null : array_values($points);
        }

        $values = SeedSignature::seededValues($sermon->date->toDateString(), $service, $sermon->content_type->value);

        if ($values !== null
            && $sermon->preacher_source?->value === 'manual'
            && $values['preacher_source'] === 'manual') {
            $updates['preacher_source'] = PreacherSource::Default;

            if (! $sermon->needs_preacher_review) {
                $updates['needs_preacher_review'] = true;
            }
        }

        if (SeedSignature::isSeededProcessingId($sermon->livestream_processing_id)) {
            $updates['livestream_processing_id'] = $run->processing_id;
        }

        foreach ($updates as $field => $after) {
            $this->lines[] = ['sermons', "{$sermon->id}", $field, $this->show($sermon->getAttribute($field)), $this->show($after)];
        }

        if ($updates === []) {
            return;
        }

        $this->actions[] = function () use ($sermon, $updates): void {
            $sermon->forceFill($updates)->save();
            app(SermonScriptureFilterIndexService::class)->syncForSermon($sermon->refresh());
        };
    }

    private function planUnattachedSermon(Sermon $sermon): void
    {
        $label = "sermon {$sermon->id} ({$sermon->slug})";
        $references = [
            'service_sections.published_sermon_id' => DB::table('service_sections')->where('published_sermon_id', $sermon->id)->count(),
            'speaker_samples.sermon_id' => DB::table('speaker_samples')->where('sermon_id', $sermon->id)->count(),
            'media_processing_logs.sermon_id' => MediaProcessingLog::query()->where('sermon_id', $sermon->id)->where('processing_id', '!=', SeedSignature::PROCESSING_ID)->count(),
        ];

        foreach ($references as $where => $count) {
            if ($count > 0) {
                $this->refusals[] = "{$label} is referenced by {$count} row(s) in {$where}.";
            }
        }

        foreach (['audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path'] as $field) {
            if (filled($sermon->getAttribute($field))) {
                $this->refusals[] = "{$label} carries media in {$field}.";
            }
        }

        if (filled($sermon->livestream_processing_id) && ! SeedSignature::isSeededProcessingId($sermon->livestream_processing_id)) {
            $this->refusals[] = "{$label} names run {$sermon->livestream_processing_id} in livestream_processing_id.";
        }

        $this->lines[] = ['sermons', "{$sermon->id}", 'row', "{$sermon->slug} / {$sermon->title}", 'deleted'];
        $this->actions[] = function () use ($sermon): void {
            DB::table('media_processing_logs')->where('sermon_id', $sermon->id)->update(['sermon_id' => null]);
            $sermon->scriptureFilters()->delete();
            $sermon->delete();
        };
    }

    private function planSeedRun(): void
    {
        $run = MediaProcessingLog::query()->where('processing_id', SeedSignature::PROCESSING_ID)->first();

        if (! $run instanceof MediaProcessingLog) {
            return;
        }

        $dependents = [
            'sermon_processing_steps.processing_id' => DB::table('sermon_processing_steps')->where('processing_id', $run->processing_id)->count(),
            'livestream_segments.media_processing_log_id' => DB::table('livestream_segments')->where('media_processing_log_id', $run->id)->count(),
            'speaker_samples.media_processing_log_id' => DB::table('speaker_samples')->where('media_processing_log_id', $run->id)->count(),
            'historic_import_alerts.media_processing_log_id' => DB::table('historic_import_alerts')->where('media_processing_log_id', $run->id)->count(),
            'historic_import_nested_jobs.media_processing_log_id' => DB::table('historic_import_nested_jobs')->where('media_processing_log_id', $run->id)->count(),
        ];

        foreach ($dependents as $where => $count) {
            if ($count > 0) {
                $this->refusals[] = "seed run {$run->id} has {$count} unexpected row(s) in {$where}.";
            }
        }

        $strangers = Sermon::query()
            ->where('livestream_processing_id', $run->processing_id)
            ->get()
            ->filter(fn (Sermon $sermon): bool => SeedSignature::matchesSermon($sermon) === []);

        foreach ($strangers as $sermon) {
            $this->refusals[] = "sermon {$sermon->id} ({$sermon->slug}) is not seeded but names the seed run in livestream_processing_id.";
        }

        $sections = ServiceSection::query()->where('media_processing_log_id', $run->id)->get();

        foreach ($sections as $section) {
            $this->lines[] = ['service_sections', "{$section->id}", 'row', "run {$run->id} order {$section->section_order} {$section->title}", 'deleted'];
        }

        $this->lines[] = ['media_processing_logs', "{$run->id}", 'row', $run->processing_id, 'deleted'];
        $this->actions[] = function () use ($run): void {
            Sermon::query()->where('livestream_processing_id', $run->processing_id)->update(['livestream_processing_id' => null]);
            ServiceSection::query()->where('media_processing_log_id', $run->id)->delete();
            $run->delete();
        };
    }

    private function planServiceItems(): void
    {
        $seedRunId = MediaProcessingLog::query()->where('processing_id', SeedSignature::PROCESSING_ID)->value('id');

        $serviceIds = ChurchService::query()
            ->whereDate('date', SeedSignature::SERVICE_DATE)
            ->where('service', 'morning')
            ->pluck('id');

        $items = ChurchServiceItem::withTrashed()
            ->whereIn('church_service_id', $serviceIds)
            ->where('type', SeedSignature::SERVICE_ITEM_TYPE)
            ->where('title', SeedSignature::SERVICE_ITEM_TITLE)
            ->where('position', SeedSignature::SERVICE_ITEM_POSITION)
            ->get();

        foreach ($items as $item) {
            $label = "service item {$item->id} (service {$item->church_service_id})";
            $others = ServiceSection::query()
                ->where(function ($inner) use ($item): void {
                    $inner->where('church_service_item_id', $item->id)
                        ->orWhere('matched_item_id', $item->id)
                        ->orWhere('expected_item_id', $item->id);
                })
                ->when($seedRunId !== null, fn ($query) => $query->where('media_processing_log_id', '!=', $seedRunId))
                ->count();

            if ($others > 0) {
                $this->refusals[] = "{$label} is referenced by {$others} section(s) outside the seed run.";
            }

            if (DB::table('song_usage_reports')->where('resolved_church_service_item_id', $item->id)->exists()) {
                $this->refusals[] = "{$label} is referenced by song_usage_reports.resolved_church_service_item_id.";
            }

            if (filled($item->canonical_identity)) {
                $this->refusals[] = "{$label} carries canonical identity '{$item->canonical_identity}'.";
            }

            $this->lines[] = ['church_service_items', "{$item->id}", 'row', "position {$item->position} {$item->type} {$item->title}", 'deleted'];
            $this->actions[] = function () use ($item): void {
                $item->forceDelete();
            };
        }
    }

    private function planPreachers(): void
    {
        $preachers = Preacher::query()->whereIn('name', SeedSignature::preacherNames())->orderBy('id')->get();

        foreach ($preachers as $preacher) {
            $label = "preacher {$preacher->id} ({$preacher->name})";
            $sermons = Sermon::query()->where('preacher_id', $preacher->id)->orWhere('preacher', $preacher->name)->count();
            $profiles = DB::table('speaker_profiles')->where('preacher_id', $preacher->id)->count();
            $foreign = PreacherAlias::query()
                ->where('preacher_id', $preacher->id)
                ->where('alias', '!=', mb_strtolower($preacher->name))
                ->pluck('alias')
                ->all();

            if ($sermons > 0) {
                $this->refusals[] = "{$label} is used by {$sermons} sermon(s).";
            }

            if ($profiles > 0) {
                $this->refusals[] = "{$label} has {$profiles} speaker profile(s).";
            }

            if ($foreign !== []) {
                $this->refusals[] = "{$label} has unexpected alias(es): ".implode(', ', $foreign).'.';
            }

            $this->lines[] = ['preachers', "{$preacher->id}", 'row', $preacher->name, 'deleted'];
            $this->actions[] = function () use ($preacher): void {
                PreacherAlias::query()->where('preacher_id', $preacher->id)->delete();
                $preacher->delete();
            };
        }

        $alias = PreacherAlias::query()->where('alias', SeedSignature::ALIAS)->first();

        if ($alias instanceof PreacherAlias) {
            $this->lines[] = ['preacher_aliases', "{$alias->id}", 'row', "{$alias->alias} -> preacher {$alias->preacher_id}", 'deleted'];
            $this->actions[] = function () use ($alias): void {
                $alias->delete();
            };
        }
    }

    private function show(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            $value instanceof \BackedEnum => (string) $value->value,
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
