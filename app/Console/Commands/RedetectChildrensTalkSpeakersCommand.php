<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ServiceSection;
use App\Services\ChurchService\ChurchServiceReviewSynchronizer;
use App\Services\Preacher\ChildrensTalkSpeakerService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Re-ask the children's-talk speaker question where the stored answer was never an answer.
 *
 * `services:rederive-structure-review-flags` cannot reach this: it re-derives structure
 * flags from banked structure, and `childrens_talk_speaker_review` is raised by a voice
 * model reading audio, not by the validator. So a section keeps whichever speaker verdict
 * its run happened to reach — including two rows that have carried
 * `childrens_talk_speaker_unconfigured` since July 2026, for a condition (no active
 * profiles) that stopped being true the same month.
 *
 * **Scope is deliberately narrow, and the narrowness is the point.** Only sections whose
 * stored outcome is a disposition-class one {@see self::RE_ASKABLE_OUTCOMES}, or which
 * never got a prediction at all, are re-asked. A row holding `ambiguous` or `no_match`
 * already received a real answer from the model against audio that has since been reaped;
 * re-running it now would resolve to `missing_audio`, disposition it, and silently retire a
 * genuine open question. That is the same laundering shape as the unscoped song-match
 * recompute of 2026-09-03 — a command that looks safe because every individual write is
 * defensible.
 */
class RedetectChildrensTalkSpeakersCommand extends Command
{
    /**
     * Outcomes whose answer can still change for the better.
     *
     * Each records a fact about the inputs rather than a judgement about the audio, so
     * re-asking either produces a real verdict now or restates the same fact. Scored
     * outcomes are excluded — see the class docblock.
     *
     * @var list<string>
     */
    private const RE_ASKABLE_OUTCOMES = ['no_profiles', 'skipped', 'short_audio', 'missing_audio'];

    protected $signature = 'services:redetect-childrens-talk-speakers
        {--execute : Persist the reported changes; without this option the command is a dry run}
        {--chunk=50 : Number of sections to inspect per chunk}
        {--section=* : Restrict to these service section IDs, bypassing the outcome scope}';

    protected $description = "Re-ask the children's-talk speaker question for sections whose stored outcome was an input fact";

    public function handle(
        ChildrensTalkSpeakerService $speakerService,
        ChurchServiceReviewSynchronizer $reviewSynchronizer,
    ): int {
        $execute = (bool) $this->option('execute');
        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize < 1) {
            $this->error('The --chunk option must be a positive integer.');

            return self::FAILURE;
        }

        if (! $execute) {
            $this->warn('DRY RUN enabled by default. No rows will be updated; pass --execute to persist these changes.');
        }

        /** @var list<int> $sectionIds */
        $sectionIds = array_values(array_filter(array_map('intval', (array) $this->option('section'))));

        $inspected = 0;
        $changed = 0;
        /** @var array<string, int> $transitions */
        $transitions = [];
        /** @var array<int, int> $touchedServiceIds */
        $touchedServiceIds = [];

        ServiceSection::query()
            ->where('section_type', ServiceSectionType::ChildrensTalk->value)
            ->when($sectionIds !== [], fn ($query) => $query->whereKey($sectionIds))
            ->whereHas('processingLog', fn ($query) => $query->whereNull('superseded_at'))
            ->with('processingLog')
            ->orderBy('id')
            ->chunkById($chunkSize, function (EloquentCollection $sections) use (
                $speakerService,
                $execute,
                $sectionIds,
                &$inspected,
                &$changed,
                &$transitions,
                &$touchedServiceIds,
            ): void {
                foreach ($sections as $section) {
                    $before = $this->snapshot($section);

                    if ($sectionIds === [] && ! $this->isReAskable($before['outcome'])) {
                        continue;
                    }

                    $inspected++;

                    $speakerService->detectAndStore($section);

                    $after = $this->snapshot($section);

                    if ($before === $after) {
                        continue;
                    }

                    $changed++;
                    $key = sprintf(
                        '%s → %s (%s)',
                        $before['outcome'] ?? 'none',
                        $after['outcome'] ?? 'none',
                        $before['flagged'] === $after['flagged']
                            ? 'flag unchanged'
                            : ($after['flagged'] ? 'flag raised' : 'flag withdrawn'),
                    );
                    $transitions[$key] = ($transitions[$key] ?? 0) + 1;

                    $serviceId = $section->processingLog->church_service_id;

                    if (is_int($serviceId)) {
                        $touchedServiceIds[$serviceId] = $serviceId;
                    }

                    if ($execute) {
                        $section->saveQuietly();
                    }
                }
            });

        $syncedServices = 0;

        if ($execute && $touchedServiceIds !== []) {
            ChurchService::query()
                ->whereKey(array_values($touchedServiceIds))
                ->orderBy('id')
                ->chunkById($chunkSize, function (EloquentCollection $services) use ($reviewSynchronizer, &$syncedServices): void {
                    foreach ($services as $service) {
                        $reviewSynchronizer->reconcileServiceReview($service);
                        $syncedServices++;
                    }
                });
        }

        if ($transitions !== []) {
            $this->newLine();
            $this->line('Outcome transitions:');

            ksort($transitions);

            foreach ($transitions as $label => $count) {
                $this->line(sprintf('  %-56s %d', $label, $count));
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', $execute ? 'Applied' : 'Would apply'],
            [
                ['Sections in scope', $inspected],
                ['Sections changed', $changed],
                ['Services reconciled', $syncedServices],
            ],
        );

        return self::SUCCESS;
    }

    private function isReAskable(?string $outcome): bool
    {
        return $outcome === null || in_array($outcome, self::RE_ASKABLE_OUTCOMES, true);
    }

    /**
     * @return array{outcome: string|null, flagged: bool, reason: string|null}
     */
    private function snapshot(ServiceSection $section): array
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $outcome = $metadata['childrens_talk_speaker']['predicted']['outcome'] ?? null;
        $reason = $metadata['review_reason'] ?? null;

        return [
            'outcome' => is_string($outcome) ? $outcome : null,
            'flagged' => in_array('childrens_talk_speaker_review', (array) ($metadata['review_flags'] ?? []), true),
            'reason' => is_string($reason) ? $reason : null,
        ];
    }
}
