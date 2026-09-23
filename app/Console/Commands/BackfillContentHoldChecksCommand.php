<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\HoldSectionForContentReview;
use App\Data\ServiceSectionMetadata;
use App\Enums\ContentHoldCheck;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ContentHoldRechecker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Record, on every content hold raised before holds recorded it, which check found
 * it and the content it was found on.
 *
 * Operator ruling 2026-09-23. The 181 holds recorded only a reason, a time and an
 * evidence reference, so the check is read from the reason: every one was written
 * by a census session in a small fixed vocabulary.
 *
 * The transcript fingerprint is what decides whether the next re-check runs. A
 * hold found before the run was re-decoded ({@see self::REDECODE_KINDS}) has had
 * its repair already, so it is marked {@see ContentHoldRechecker::SUPERSEDED} and
 * its check re-runs; any other hold gets the current transcript's, and waits for a
 * repair. Where the transcript cannot be read nothing is stamped, and a later run
 * of this command stamps it.
 *
 * It also removes a record left on a row that never carried its content — the
 * sync kept records by row until now, so 1287's reading kept its song's hold.
 */
class BackfillContentHoldChecksCommand extends Command
{
    protected $signature = 'service:backfill-content-hold-checks
        {--execute : Write the records; without this option the command is a dry run}';

    protected $description = 'Record which check found each existing content hold, and drop records left on rows that never carried them';

    /** Artifacts written only by a fresh decode of the source audio. */
    private const REDECODE_KINDS = ['raw', 'normalized-region-recovered'];

    /**
     * Reason fragments, most specific first: a semantic substitution that also
     * loops is still a judgement nobody's code can make.
     *
     * @var list<array{0: string, 1: ContentHoldCheck}>
     */
    private const REASON_CHECKS = [
        ['semantic_substitution', ContentHoldCheck::Judgement],
        ['duplicate-performance', ContentHoldCheck::Decision],
        ['held by operator ruling', ContentHoldCheck::Decision],
        ['wrong song', ContentHoldCheck::LyricComparison],
        ['song identity contradicted', ContentHoldCheck::LyricComparison],
        ['fragmentation_source_mismatch', ContentHoldCheck::SourceAudio],
        ['blind', ContentHoldCheck::SourceAudio],
        ['source recording', ContentHoldCheck::SourceAudio],
        ['loop', ContentHoldCheck::LoopScreen],
        ['repetition blocks', ContentHoldCheck::LoopScreen],
        ['cadence', ContentHoldCheck::LoopScreen],
        ['mp3 ends', ContentHoldCheck::MediaMeasurement],
        ['amen_truncated', ContentHoldCheck::MediaMeasurement],
        ['title or scripture reference', ContentHoldCheck::Judgement],
    ];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $sections = ServiceSection::query()
            ->with('processingLog')
            ->whereNotNull('metadata->'.HoldSectionForContentReview::METADATA_KEY)
            ->orderBy('id')
            ->get();

        $checks = [];
        $removed = [];
        $fingerprints = [];
        $stamps = ['repaired since the hold' => 0, 'waiting for a repair' => 0];
        $unreadable = 0;

        DB::transaction(function () use ($sections, $execute, &$checks, &$removed, &$fingerprints, &$stamps, &$unreadable): void {
            foreach ($sections as $section) {
                $metadata = $section->metadata?->toArray() ?? [];

                if ($this->isLeftBehind($section, $sections)) {
                    $removed[] = $section->id;
                    unset($metadata[HoldSectionForContentReview::METADATA_KEY]);
                    $this->write($section, $metadata, $execute);

                    continue;
                }

                $run = $section->processingLog;
                $fingerprints[$run->id] ??= $run->serviceTranscriptSha256();
                $changed = false;

                $records = array_map(function (array $record) use ($section, $run, $fingerprints, &$checks, &$changed, &$stamps, &$unreadable): array {
                    if (! isset($record['found_by'])) {
                        $check = $this->checkFor(is_string($record['reason'] ?? null) ? $record['reason'] : '');
                        $checks[$check->value] = ($checks[$check->value] ?? 0) + 1;
                        $changed = true;
                        $record = [
                            ...$record,
                            'found_by' => $check->value,
                            'start_time' => (float) $section->start_time,
                            'end_time' => (float) $section->end_time,
                            'church_service_item_id' => $section->church_service_item_id,
                        ];
                    }

                    if (($record['transcript_sha256'] ?? null) === null && HoldSectionForContentReview::isLive($record) && ! isset($record['rechecked_at'])) {
                        $stamp = $this->redecodedSince($run, $record['held_at'] ?? null)
                            ? ContentHoldRechecker::SUPERSEDED
                            : $fingerprints[$run->id];

                        if ($stamp === null) {
                            $unreadable++;
                        } else {
                            $record['transcript_sha256'] = $stamp;
                            $stamps[$stamp === ContentHoldRechecker::SUPERSEDED ? 'repaired since the hold' : 'waiting for a repair']++;
                            $changed = true;
                        }
                    }

                    return $record;
                }, HoldSectionForContentReview::holdsIn($metadata));

                if ($changed) {
                    $metadata[HoldSectionForContentReview::METADATA_KEY] = $records;
                    $this->write($section, $metadata, $execute);
                }
            }
        });

        ksort($checks);
        $this->table(['Found by', 'Holds'], collect($checks)->map(fn (int $count, string $check): array => [$check, $count])->values()->all());
        $this->line(sprintf('Transcript fingerprints: %d repaired since the hold, %d waiting for a repair, %d unreadable (not stamped).', $stamps['repaired since the hold'], $stamps['waiting for a repair'], $unreadable));
        $this->line('Records left on rows that never carried them: '.($removed === [] ? 'none' : 'sections '.implode(', ', $removed)));

        if (! $execute) {
            $this->warn('DRY RUN: nothing was written. Re-run with --execute to record these.');
        }

        return self::SUCCESS;
    }

    private function checkFor(string $reason): ContentHoldCheck
    {
        $reason = mb_strtolower($reason);

        foreach (self::REASON_CHECKS as [$fragment, $check]) {
            if (str_contains($reason, $fragment)) {
                return $check;
            }
        }

        return ContentHoldCheck::Boundary;
    }

    private function redecodedSince(MediaProcessingLog $run, mixed $heldAt): bool
    {
        if (! is_string($heldAt)) {
            return false;
        }

        $held = CarbonImmutable::parse($heldAt);
        $artifacts = ($run->processing_metadata?->toArray() ?? [])['service_artifacts'] ?? [];

        foreach (is_array($artifacts) ? $artifacts : [] as $artifact) {
            if (is_array($artifact)
                && in_array($artifact['kind'] ?? null, self::REDECODE_KINDS, true)
                && is_string($artifact['recorded_at'] ?? null)
                && CarbonImmutable::parse($artifact['recorded_at'])->greaterThan($held)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A row that cannot be held, is not held, and whose every record is also on a
     * section of the same run: a copy the order-keyed sync left behind.
     *
     * @param  Collection<int, ServiceSection>  $sections
     */
    private function isLeftBehind(ServiceSection $section, Collection $sections): bool
    {
        $metadata = $section->metadata?->toArray() ?? [];

        if (HoldSectionForContentReview::canHold($section) || HoldSectionForContentReview::isHeld($section->metadata->reviewFlags ?? [])) {
            return false;
        }

        $claims = $this->claims(HoldSectionForContentReview::holdsIn($metadata));
        $elsewhere = $sections
            ->filter(static fn (ServiceSection $other): bool => $other->id !== $section->id
                && $other->media_processing_log_id === $section->media_processing_log_id
                && HoldSectionForContentReview::canHold($other))
            ->flatMap(fn (ServiceSection $other): array => $this->claims(HoldSectionForContentReview::holdsIn($other->metadata?->toArray() ?? [])))
            ->all();

        return $claims !== [] && array_diff($claims, $elsewhere) === [];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<string>
     */
    private function claims(array $records): array
    {
        return array_map(
            static fn (array $record): string => json_encode([$record['reason'] ?? null, $record['evidence'] ?? null], JSON_THROW_ON_ERROR),
            $records,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function write(ServiceSection $section, array $metadata, bool $execute): void
    {
        if (! $execute) {
            return;
        }

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->save();
    }
}
