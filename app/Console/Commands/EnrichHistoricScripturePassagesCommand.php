<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricScripturePassageRequirements;
use App\Services\Scripture\ApiBibleClient;
use App\Services\Scripture\ScriptureOperatorService;
use Illuminate\Console\Command;
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
 */
class EnrichHistoricScripturePassagesCommand extends Command
{
    protected $signature = 'historic-import:enrich-scripture-passages
        {media-bundle : Private Bundle A JSON file}
        {--apply : Fetch the missing passages; without it the command only reports}
        {--delay=500 : Milliseconds to sleep between API calls}';

    protected $description = 'Report or fetch the Scripture Passages a historic media bundle requires';

    public function handle(
        HistoricProcessingResultBundle $bundles,
        HistoricScripturePassageRequirements $requirements,
        ScriptureOperatorService $scripture,
        ApiBibleClient $client,
    ): int {
        try {
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

        return $this->fetch($missing, $requirements, $scripture, $client);
    }

    /**
     * @param  list<array{bible_id: string, normalized_reference: string}>  $missing
     */
    private function fetch(
        array $missing,
        HistoricScripturePassageRequirements $requirements,
        ScriptureOperatorService $scripture,
        ApiBibleClient $client,
    ): int {
        $delayMs = (int) $this->option('delay');
        $resolved = 0;
        $unresolved = [];
        $budgetExhausted = false;

        $this->newLine();

        foreach ($missing as $key) {
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
            } catch (Throwable $exception) {
                $unresolved[] = "{$identity} ({$exception->getMessage()})";

                continue;
            }

            if ($outcome['passage'] === null) {
                $this->warn("  {$identity}: {$outcome['status']}");
                $unresolved[] = "{$identity} ({$outcome['status']})";

                continue;
            }

            $this->line("  {$identity}: {$outcome['status']}");
            $resolved++;

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
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
