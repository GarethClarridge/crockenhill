<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\HoldSectionForContentReview;
use App\Actions\ReleaseSectionContentHold;
use App\Models\ServiceSection;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Release one content hold after checking the content it was about (see
 * {@see ReleaseSectionContentHold}). Dry-run by default: it names the hold it would release.
 */
class ReleaseContentHoldCommand extends Command
{
    protected $signature = 'service:release-content-hold
        {section : The held section id}
        {--held-at= : The hold record\'s held_at, when the section carries more than one live hold}
        {--because= : Why the content is now right, kept on the record}
        {--execute : Release; without this option the command only names the hold}';

    protected $description = 'Release one content hold the operator has checked, keeping its record';

    public function handle(ReleaseSectionContentHold $release): int
    {
        $section = ServiceSection::find((int) $this->argument('section'));

        if (! $section instanceof ServiceSection) {
            $this->error('Section not found.');

            return self::FAILURE;
        }

        $heldAt = is_string($this->option('held-at')) && $this->option('held-at') !== '' ? $this->option('held-at') : null;
        $live = array_values(array_filter(
            HoldSectionForContentReview::holdsIn($section->metadata?->toArray() ?? []),
            static fn (array $record): bool => HoldSectionForContentReview::isLive($record) && ($heldAt === null || ($record['held_at'] ?? null) === $heldAt),
        ));

        $this->table(['Held at', 'Found by', 'Reason'], array_map(
            static fn (array $record): array => [$record['held_at'] ?? '–', $record['found_by'] ?? '–', mb_strimwidth((string) ($record['reason'] ?? ''), 0, 90, '…')],
            $live,
        ));

        if (! $this->option('execute')) {
            $this->warn('DRY RUN: nothing released. Pass --because and --execute to release.');

            return count($live) === 1 ? self::SUCCESS : self::FAILURE;
        }

        try {
            $record = $release($section, $heldAt, (string) $this->option('because'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $section->refresh();
        $this->info(sprintf(
            'Released the hold held at %s on section %d. Live holds left: %d. Needs review: %s.',
            $record['held_at'] ?? 'an unrecorded time',
            $section->id,
            count(array_filter(HoldSectionForContentReview::holdsIn($section->metadata?->toArray() ?? []), HoldSectionForContentReview::isLive(...))),
            $section->needs_manual_review ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
