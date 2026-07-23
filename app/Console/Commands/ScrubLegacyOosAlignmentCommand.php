<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ServiceSectionMetadata;
use App\Models\ServiceSection;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Clears heuristic-era Order-of-Service alignment fossils from service sections.
 *
 * The heuristic aligner stack (StructuralSectionAligner, SongSectionAligner,
 * OosAlignmentService, PresentationItemClassifier, …) was deleted on 2026-07-20.
 * The LLM-first pipeline now owns OoS anchoring purely through the
 * `church_service_item_id` FK and never writes `oos_alignment`,
 * `expected_item_id`, or `matched_item_id`. Any section still carrying those
 * fields is therefore a legacy fossil rendered by the timeline's fallback
 * branches — producing stale "Mismatch" banners such as the Welcome section
 * force-aligned to a Heidelberg slide deck.
 *
 * These runs cannot be reprocessed (their full-service media/transcript no
 * longer survive on the temp disk), so scrubbing the fossil fields is the only
 * remediation. Clearing them lets the timeline fall back to the clean FK, and
 * genuinely-unaligned plan items reappear as honest plan-only rows.
 */
class ScrubLegacyOosAlignmentCommand extends Command
{
    protected $signature = 'service:scrub-legacy-alignment
                            {--service=* : Limit to these church service IDs}
                            {--include-superseded : Also scrub superseded runs (default: live runs only)}
                            {--apply : Write changes (default: dry-run — nothing is persisted)}';

    protected $description = 'Clear heuristic-era OoS alignment fossils (oos_alignment / expected_item_id / matched_item_id) from service sections';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        /** @var list<string> $serviceIds */
        $serviceIds = (array) $this->option('service');
        $includeSuperseded = (bool) $this->option('include-superseded');

        $sections = $this->fossilSections($serviceIds, $includeSuperseded);

        if ($sections->isEmpty()) {
            $this->info('No legacy alignment fossils found for the given scope.');

            return self::SUCCESS;
        }

        $this->line($apply
            ? '<fg=yellow>APPLYING</> — the following fossils will be cleared:'
            : '<fg=cyan>DRY RUN</> — the following fossils would be cleared (re-run with --apply to persist):');
        $this->newLine();

        $rows = [];
        $mismatchCount = 0;

        foreach ($sections as $section) {
            $run = $section->processingLog;
            $metadata = $section->metadata;
            $raw = $metadata instanceof ServiceSectionMetadata ? $metadata->raw : [];
            $oos = is_array($raw['oos_alignment'] ?? null) ? $raw['oos_alignment'] : [];
            $mismatch = isset($oos['mismatch_reason']) && is_string($oos['mismatch_reason']);
            if ($mismatch) {
                $mismatchCount++;
            }

            $cleared = [];
            if (array_key_exists('oos_alignment', $raw)) {
                $cleared[] = 'oos_alignment';
            }
            if ($section->expected_item_id !== null) {
                $cleared[] = "expected_item_id={$section->expected_item_id}";
            }
            if ($section->matched_item_id !== null) {
                $cleared[] = "matched_item_id={$section->matched_item_id}";
            }

            $detail = $mismatch
                ? sprintf(
                    '%s → %s',
                    $section->section_type->value,
                    is_string($oos['expected_item_title'] ?? null) ? $oos['expected_item_title'] : '(untitled)',
                )
                : '—';

            $rows[] = [
                $run->church_service_id ?? '?',
                $section->media_processing_log_id,
                $run->superseded_at !== null ? 'superseded' : 'live',
                $section->id,
                $mismatch ? 'MISMATCH' : 'benign',
                $detail,
                implode(', ', $cleared),
            ];

            if ($apply) {
                $this->scrub($section, $raw);
            }
        }

        $this->table(
            ['svc', 'run', 'run_state', 'section', 'kind', 'detail', 'cleared_fields'],
            $rows,
        );

        $this->newLine();
        $this->info(sprintf(
            '%s %d section(s) across %d run(s) / %d service(s) — %d carried a visible mismatch banner.',
            $apply ? 'Scrubbed' : 'Would scrub',
            $sections->count(),
            $sections->pluck('media_processing_log_id')->unique()->count(),
            $sections->map(fn (ServiceSection $s): ?int => $s->processingLog->church_service_id)->filter()->unique()->count(),
            $mismatchCount,
        ));

        if (! $apply) {
            $this->newLine();
            $this->comment('No changes written. Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $serviceIds
     * @return Collection<int, ServiceSection>
     */
    private function fossilSections(array $serviceIds, bool $includeSuperseded): Collection
    {
        return ServiceSection::query()
            ->with('processingLog')
            ->where(function (Builder $query): void {
                $query->whereNotNull('expected_item_id')
                    ->orWhereNotNull('matched_item_id')
                    ->orWhere('metadata->oos_alignment', '!=', null);
            })
            ->whereHas('processingLog', function (Builder $query) use ($serviceIds, $includeSuperseded): void {
                if ($serviceIds !== []) {
                    $query->whereIn('church_service_id', $serviceIds);
                }

                if (! $includeSuperseded) {
                    $query->whereNull('superseded_at');
                }
            })
            ->orderBy('media_processing_log_id')
            ->orderBy('section_order')
            ->get();
    }

    /**
     * Clear the fossil fields, preserving every other metadata key.
     *
     * @param  array<string, mixed>  $raw
     */
    private function scrub(ServiceSection $section, array $raw): void
    {
        unset($raw['oos_alignment']);

        $section->metadata = $raw === [] ? null : ServiceSectionMetadata::fromArray($raw);
        $section->expected_item_id = null;
        $section->matched_item_id = null;
        $section->save();
    }
}
