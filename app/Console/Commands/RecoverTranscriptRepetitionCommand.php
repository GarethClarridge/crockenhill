<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\FlagSermonTextPredatesEvidence;
use App\Actions\FlagSuspectTranscriptRepetition;
use App\Contracts\ServiceTranscriptionInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\SuspectTranscriptBlock;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use App\Services\Media\Audio\ServiceTranscriptReader;
use App\Services\Media\Audio\ServiceTranscriptRepetitionRecovery;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Re-decode the looping stretches of banked transcripts and keep what the audio
 * actually yields (P8-Q14).
 *
 * **Deliberately stops at the transcript.** It does not re-detect structure, does
 * not re-derive sermon text, and does not dispatch analysis. Those read the
 * sermon's *spans*, and P8-Q15 is still to settle how a sermon delivered in
 * several parts around a hymn is represented — re-detecting now would re-derive
 * those services under a validator that is about to change, and regenerating
 * analysis now would pay for it twice. The plan's own sequence separates them
 * for this reason: recover evidence, then regenerate once from settled spans.
 *
 * What makes deferring safe is the stamp. `service_transcript_content` records
 * the transcript this run now holds, and `CreateSermonTranscriptFromService`
 * records the one each sermon text was sliced from, so
 * {@see MediaProcessingLog::sermonDerivationIsOwed()} can enumerate exactly the
 * runs whose sermon still describes the loop. Without it a recovered run would
 * read as untouched, which is the invisibility that made the 2026-09-07
 * freshness loss unrecoverable.
 *
 * The pre-recovery transcript is left where it is and the recovered one written
 * under its own artifact kind, so the evidence for what was replaced survives
 * the replacement.
 */
class RecoverTranscriptRepetitionCommand extends Command
{
    /** Kept distinct so the pre-recovery transcript survives its own correction. */
    public const RECOVERED_KIND = 'normalized-repetition-recovered';

    protected $signature = 'service:recover-transcript-repetition
        {--run=* : Processing run ids to recover}
        {--operation=* : Historic import operation ids to recover}
        {--limit= : Stop after this many runs that had something to recover}
        {--execute : Re-decode and write; without this the command only reports what it would do}
        {--json : Emit the report as JSON}';

    protected $description = 'Re-decode looping transcript blocks from source audio, keeping surrounding text intact';

    public function handle(
        ServiceTranscriptReader $transcripts,
        ServiceTranscriptRepetitionScreen $screen,
        ServiceTranscriptRepetitionRecovery $recovery,
        ServiceAudioWindowExtractor $extractor,
        ServiceTranscriptionInterface $transcription,
        ServiceArtifactStorage $artifacts,
        FlagSuspectTranscriptRepetition $hold,
        HistoricStagingContextRegistry $stagingContexts,
    ): int {
        try {
            $runs = $this->selection();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($runs === null) {
            $this->error('Name what to recover: --run or --operation.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');
        $report = [
            'executed' => (bool) $this->option('execute'),
            'considered' => 0,
            'no_blocks' => 0,
            'source_unavailable' => 0,
            'attempted' => 0,
            'failed' => 0,
            'blocks' => 0,
            'blocks_recovered' => 0,
            'blocks_unavailable' => 0,
            'blocks_still_looping' => 0,
            'looped_words_removed' => 0,
            'words_recovered' => 0,
            'holds_withdrawn' => 0,
            'derivation_holds_raised' => 0,
            'runs' => [],
        ];

        foreach ($runs->lazyById() as $run) {
            if ($limit !== null && $report['attempted'] >= $limit) {
                break;
            }

            $report['considered']++;

            $outcome = $this->withRunContext($stagingContexts, $run, function () use (
                $run, $transcripts, $screen, $recovery, $extractor, $transcription, $artifacts, $hold, &$report
            ): string {
                $transcript = $transcripts->tryRead($run);

                if (! $transcript instanceof ChurchServiceTranscript) {
                    return 'transcript unreadable';
                }

                if ($screen->screen($transcript) === []) {
                    $report['no_blocks']++;

                    return 'nothing to recover';
                }

                $source = $this->localSource($run);

                if ($source === null) {
                    $report['source_unavailable']++;

                    return 'source unavailable';
                }

                $report['attempted']++;

                return $this->recoverRun(
                    $run, $transcript, $source, $recovery, $extractor, $transcription, $artifacts, $hold, $report,
                );
            });

            if ($outcome !== 'nothing to recover') {
                $report['runs'][] = ['run' => $run->id, 'sermon' => $run->sermon_id, 'outcome' => $outcome];
            }
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($report);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $report */
    private function recoverRun(
        MediaProcessingLog $run,
        ChurchServiceTranscript $transcript,
        string $source,
        ServiceTranscriptRepetitionRecovery $recovery,
        ServiceAudioWindowExtractor $extractor,
        ServiceTranscriptionInterface $transcription,
        ServiceArtifactStorage $artifacts,
        FlagSuspectTranscriptRepetition $hold,
        array &$report,
    ): string {
        $execute = (bool) $this->option('execute');

        try {
            $result = $recovery->recover(
                $transcript,
                function (float $start, float $end, SuspectTranscriptBlock $block) use ($run, $source, $extractor, $transcription, $execute): ?ChurchServiceTranscript {
                    if (! $execute) {
                        return null;
                    }

                    return $this->decode($run, $source, $start, $end, $extractor, $transcription);
                },
            );
        } catch (Throwable $exception) {
            $report['failed']++;
            $this->warn("run {$run->id}: {$exception->getMessage()}");

            return 'failed: '.$exception->getMessage();
        }

        $report['blocks'] += $result->blocks;
        $report['blocks_recovered'] += $result->recovered;
        $report['blocks_unavailable'] += $result->unavailable;
        $report['blocks_still_looping'] += $result->stillLooping;
        $report['looped_words_removed'] += $result->recovered > 0 ? $result->loopedWordsRemoved : 0;
        $report['words_recovered'] += $result->wordsRecovered;

        if (! $execute) {
            return sprintf('would re-decode %d block(s)', $result->blocks);
        }

        if (! $result->changedAnything()) {
            return 'no block could be reached';
        }

        $path = $artifacts->putJson($run->processing_id, self::RECOVERED_KIND, $result->transcript->toArray());

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen($result->transcript);

        $run->putServiceTranscriptPath(
            $path,
            $result->transcript->unobservableWindows,
            array_map(static fn (SuspectTranscriptBlock $block): array => $block->toArray(), $blocks),
        );

        // Stamped before the hold is revisited: the hold can be withdrawn here,
        // and a withdrawal with no record of why would leave a repaired sermon
        // indistinguishable from one that never looped.
        $run->recordServiceTranscriptContent(MediaProcessingLog::hashServiceTranscriptContent($result->transcript));

        $outcome = $hold($run, $blocks);
        $report['holds_withdrawn'] += $outcome['withdrawn'];

        // The loop is gone from the transcript, so the repetition hold above is
        // rightly withdrawn — but the saved sermon text was sliced from the
        // transcript this run has just replaced and still contains every
        // repetition. Withdrawing one hold without raising the other empties
        // the queue while changing nothing a reader sees.
        $report['derivation_holds_raised'] += app(FlagSermonTextPredatesEvidence::class)($run)['raised'];

        return sprintf(
            'recovered %d of %d block(s), %d still looping, +%d words',
            $result->recovered,
            $result->blocks,
            $result->stillLooping,
            $result->wordsRecovered,
        );
    }

    private function decode(
        MediaProcessingLog $run,
        string $source,
        float $start,
        float $end,
        ServiceAudioWindowExtractor $extractor,
        ServiceTranscriptionInterface $transcription,
    ): ?ChurchServiceTranscript {
        $clip = null;

        try {
            $clip = $extractor->extract($source, $start, $end, $run->processing_id);

            // No priming, for the reason ServiceTranscriptRecovery records: the
            // configured whole-service prompt makes the model invent
            // service-shaped speech over music, reproducing the pathology.
            return $transcription->transcribeService(
                $clip,
                $run->processing_id.'-repetition-'.((int) round($start)),
                '',
            );
        } catch (Throwable $exception) {
            $this->warn("run {$run->id} window {$start}-{$end}: {$exception->getMessage()}");

            return null;
        } finally {
            if ($clip !== null) {
                $extractor->delete($clip);
            }
        }
    }

    private function localSource(MediaProcessingLog $run): ?string
    {
        $path = $run->source_file_path;

        if (! is_string($path) || $path === '') {
            return null;
        }

        $disk = Storage::disk((string) config('media-processing.storage.temp_disk', 'local'));

        return $disk->exists($path) ? $disk->path($path) : null;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function withRunContext(HistoricStagingContextRegistry $stagingContexts, MediaProcessingLog $run, Closure $callback): mixed
    {
        $context = $run->historicStagingContext();

        return $context === null ? $callback() : $stagingContexts->within($context, $callback);
    }

    /** @return Builder<MediaProcessingLog>|null */
    private function selection(): ?Builder
    {
        /** @var list<string> $runIds */
        $runIds = (array) $this->option('run');
        /** @var list<string> $operationIds */
        $operationIds = (array) $this->option('operation');

        $query = MediaProcessingLog::query()->orderBy('id');

        if ($runIds !== []) {
            $wanted = array_map('intval', $runIds);
            $found = $query->clone()->whereIn('id', $wanted)->pluck('id')->all();
            $missing = array_values(array_diff($wanted, $found));

            if ($missing !== []) {
                // Named runs that cannot be found are an error, never an empty
                // pass. A run is invisible whenever the app is pointed at
                // another database — which is what `artisan dusk` does for the
                // length of its suite — and reporting that as "nothing to do"
                // silently skips real work while looking like success.
                throw new RuntimeException(
                    'These runs could not be found: '.implode(', ', $missing)
                    .'. Check nothing has repointed the database, such as a Dusk run in progress.'
                );
            }

            return $query->whereIn('id', $wanted);
        }

        if ($operationIds !== []) {
            return $query->whereIn('historic_import_operation_id', array_map('intval', $operationIds));
        }

        return null;
    }

    /** @param  array<string, mixed>  $report */
    private function render(array $report): void
    {
        $this->table(['Measure', 'Count'], [
            ['Runs considered', $report['considered']],
            ['Nothing to recover', $report['no_blocks']],
            ['Source unavailable', $report['source_unavailable']],
            ['Attempted', $report['attempted']],
            ['Failed', $report['failed']],
            ['Blocks seen', $report['blocks']],
            ['Blocks recovered', $report['blocks_recovered']],
            ['Blocks still looping after retry', $report['blocks_still_looping']],
            ['Words recovered', $report['words_recovered']],
            ['Repetition holds withdrawn', $report['holds_withdrawn']],
            ['Stale-derivation holds raised', $report['derivation_holds_raised']],
        ]);

        if (! (bool) $report['executed']) {
            $this->comment('Nothing was decoded or written. Pass --execute to recover.');
        }
    }
}
