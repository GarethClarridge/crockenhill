<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricScripturePassageRequirements;
use App\Services\Scripture\ApiBibleClient;
use App\Services\Scripture\ScriptureOperatorService;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Decision D3's pre-apply Scripture enrichment pass.
 *
 * Bundle A carries Scripture Passages as natural keys only, so an apply relinks
 * against passages the destination already holds. Production has effectively
 * never run enrichment, so without this pass the apply's preflight refuses the
 * whole operation — correctly, but with nothing the operator can do inside the
 * window, since fetching hundreds of passages from api.bible is not window work.
 *
 * Run it before the window, against the exact bundle that will be applied. The
 * read-only default is the runbook's gate: exit 0 means the destination can
 * satisfy every identity in the bundle.
 *
 * api.bible renders cross-chapter ranges without the second chapter's colon
 * ("Joshua 4:1-5:1" comes back as "Joshua 4:1-51"). ScriptureOperatorService
 * distrusts a display reference whose span differs from the normalized one and
 * logs a warning; watch the log for those during a large pass.
 *
 * Delete after the exact production import and its accepted rollback/retention
 * window prove no further Bundle A enrichment is required (G9/WP10).
 */
class EnrichHistoricScripturePassagesCommand extends Command
{
    /**
     * A delay longer than a minute between calls is always a typo — a corpus
     * pass of several hundred identities would outlast the window it is meant
     * to run before.
     */
    private const int MAX_DELAY_MS = 60_000;

    protected $signature = 'historic-import:enrich-scripture-passages
        {media-bundle : Private Bundle A JSON file}
        {--apply : Fetch the missing passages; without it the command only reports}
        {--delay=500 : Milliseconds to sleep between API calls (0-60000)}';

    protected $description = 'Report or fetch the Scripture Passages a historic media bundle requires';

    public function handle(
        HistoricProcessingResultBundle $bundles,
        HistoricScripturePassageRequirements $requirements,
        ScriptureOperatorService $scripture,
        ApiBibleClient $client,
    ): int {
        try {
            $delayMs = $this->delayMilliseconds();
            $bundle = $bundles->validate($this->readBundle((string) $this->argument('media-bundle')));
            $required = $requirements->forBundle($bundle);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $missing = $requirements->missing($required);

        $this->line('Required passage identities: '.count($required));
        $this->line('Already in the destination:  '.(count($required) - count($missing)));
        $this->line('Missing:                     '.count($missing));

        if ($missing === []) {
            $this->info('The destination can satisfy every Scripture Passage this bundle requires.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Missing identities:');

            foreach ($missing as $key) {
                $this->line('  '.$requirements->identity($key));
            }

            $this->newLine();
            $this->line('Fetch them with --apply before the production window. An apply refuses the');
            $this->line('whole operation while any of these is absent.');

            return self::FAILURE;
        }

        /**
         * A disabled client would otherwise report every identity as an
         * unremarkable `not_found` and exit as though the pass had genuinely
         * run against api.bible.
         */
        if (! config('services.api_bible.enabled')) {
            $this->error('api.bible is disabled (API_BIBLE_ENABLED=false); no passage can be fetched.');

            return self::FAILURE;
        }

        return $this->fetch($missing, $delayMs, $requirements, $scripture, $client);
    }

    /**
     * The delay paces *attempts*, not successes.
     *
     * HIR3's finding was that the loop reached its sleep only after a resolved
     * passage, so a run dominated by unresolvable references — which is exactly
     * what a historic corpus produces — issued its api.bible calls as fast as
     * the loop executed. The sleep therefore lives in a `finally` covering every
     * path out of an attempt: resolved, not found and thrown alike.
     *
     * Nothing sleeps where no call was made. A budget-exhausted item never
     * reaches the client, and the last item has nothing left to pace against.
     *
     * @param  list<array{bible_id: string, normalized_reference: string}>  $missing
     */
    private function fetch(
        array $missing,
        int $delayMs,
        HistoricScripturePassageRequirements $requirements,
        ScriptureOperatorService $scripture,
        ApiBibleClient $client,
    ): int {
        $resolved = 0;
        $unresolved = [];
        $budgetExhausted = false;
        $lastIndex = array_key_last($missing);

        $this->newLine();

        foreach ($missing as $index => $key) {
            $identity = $requirements->identity($key);

            if (! $client->hasDailyBudget()) {
                $budgetExhausted = true;
                $unresolved[] = "{$identity} (budget_exhausted)";

                continue;
            }

            try {
                $outcome = $scripture->ensurePassage(
                    $key['bible_id'],
                    $key['normalized_reference'],
                    'historic-import:enrich-scripture-passages',
                );

                if ($outcome['passage'] === null) {
                    $this->warn("  {$identity}: {$outcome['status']}");
                    $unresolved[] = "{$identity} ({$outcome['status']})";

                    continue;
                }

                $this->line("  {$identity}: {$outcome['status']}");
                $resolved++;
            } catch (Throwable $exception) {
                $unresolved[] = "{$identity} ({$exception->getMessage()})";
            } finally {
                if ($delayMs > 0 && $index !== $lastIndex) {
                    Sleep::for($delayMs)->milliseconds();
                }
            }
        }

        $this->newLine();
        $this->line("Resolved: {$resolved}");

        if ($unresolved === []) {
            $this->info('Every required Scripture Passage is now present.');

            return self::SUCCESS;
        }

        if ($budgetExhausted) {
            $this->warn('The daily api.bible budget was exhausted; re-run to continue.');
        }

        $this->error('Unresolved: '.count($unresolved));

        foreach ($unresolved as $entry) {
            $this->line("  {$entry}");
        }

        $this->newLine();
        $this->line('An identity that stays unresolved needs a curation decision: correct the source');
        $this->line('reference, or settle the publication on an approved terminal absence before export.');

        return self::FAILURE;
    }

    /**
     * An unparsable or out-of-range delay is refused rather than coerced.
     * `(int) '-500'` is a negative sleep and `(int) 'fast'` is no delay at all,
     * both of which read as "the operator asked for pacing and got none".
     *
     * @throws RuntimeException
     */
    private function delayMilliseconds(): int
    {
        $value = trim((string) $this->option('delay'));

        if (preg_match('/\A\d+\z/', $value) !== 1) {
            throw new RuntimeException(
                '--delay must be a whole number of milliseconds between 0 and '.self::MAX_DELAY_MS.", not '{$value}'."
            );
        }

        $delay = (int) $value;

        if ($delay > self::MAX_DELAY_MS) {
            throw new RuntimeException(
                "--delay of {$delay}ms exceeds the ".self::MAX_DELAY_MS.'ms bound; a whole-corpus pass would not finish.'
            );
        }

        return $delay;
    }

    /** @return array<string, mixed> */
    private function readBundle(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Bundle file is missing: {$path}");
        }

        $bundle = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($bundle)) {
            throw new RuntimeException("Bundle file is not a JSON object: {$path}");
        }

        return $bundle;
    }
}
