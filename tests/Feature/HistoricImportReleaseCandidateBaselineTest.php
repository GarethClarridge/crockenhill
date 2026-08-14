<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Import\HistoricImportProductionGuard;
use App\Services\Import\HistoricImportResourceIdentity;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * HIR0's change-control baseline: the checks that must hold on every release
 * candidate while the historic import safety remediation is outstanding.
 *
 * Production is NO-GO. The two things that could quietly stop being true — an
 * authorisation leaking into a committed environment, and a one-shot losing the
 * deletion trigger that keeps it removable — are asserted here rather than left
 * to a reviewer noticing.
 *
 * @see docs/archived-plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md §6 (HIR0) steps 2 and 6
 */
class HistoricImportReleaseCandidateBaselineTest extends TestCase
{
    /**
     * HIR0 step 2. `HISTORIC_IMPORT_PRODUCTION_APPROVAL` stays unset everywhere
     * except a separately approved window, so no committed environment may
     * carry a live value and the shipped defaults must resolve to no
     * authorisation at all.
     *
     * The commented example line in `.env.example` is documentation, which is
     * why the assertion is about uncommented assignments rather than the
     * variable name appearing.
     */
    #[Test]
    public function no_committed_environment_carries_a_production_import_authorisation(): void
    {
        $variables = [
            'HISTORIC_IMPORT_PRODUCTION_APPROVAL',
            'HISTORIC_IMPORT_PRODUCTION_TARGET_FINGERPRINT',
            'HISTORIC_IMPORT_EVIDENCE_SIGNING_KEY',
        ];

        foreach (['.env.example', '.env.testing', '.env.dusk.ci', '.env.jules'] as $file) {
            $path = base_path($file);

            if (! is_file($path)) {
                continue;
            }

            foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
                foreach ($variables as $variable) {
                    if (preg_match("/^\s*{$variable}\s*=\s*(\S.*)$/", $line, $matches) === 1) {
                        $this->fail("{$file} assigns {$variable}={$matches[1]}; a release candidate must ship none.");
                    }
                }
            }
        }

        $this->addToAssertionCount(1);
    }

    /**
     * HIR0 step 2, the runtime half: with the shipped configuration there is no
     * usable production import authorisation, and a process that believes it is
     * production is refused.
     *
     * The cutoff and quarantine prerequisites are satisfied deliberately. Those
     * are separate refusals, and leaving either unset would let this pass while
     * saying nothing about the approval — the guard would simply have refused
     * earlier for an unrelated reason.
     */
    #[Test]
    public function the_shipped_configuration_authorises_no_production_import(): void
    {
        Config::set('church.services.public_from', '2026-01-01');
        Config::set('media-processing.storage.sermon_disk', 'public');
        Config::set('media-processing.storage.historic_quarantine_disk', 'historic_quarantine');
        Config::set('filesystems.disks.historic_quarantine.visibility', 'private');

        $this->app['env'] = 'production';
        $guard = new HistoricImportProductionGuard($this->app, app(HistoricImportResourceIdentity::class));

        $this->assertNull($guard->approvedOperationId());

        foreach (['historic:apply', 'historic-import:release-batch'] as $command) {
            $refusal = $guard->refusalFor($command);

            $this->assertNotNull($refusal);
            $this->assertStringContainsString('HISTORIC_IMPORT_PRODUCTION_APPROVAL', $refusal);
        }
    }

    /**
     * HIR1 step 5. Every environment capable of historic mutation must carry
     * syntactically valid production anchors, and the shipped ones must match
     * nothing — a checkout is a rehearsal target, never production.
     *
     * Enforced here rather than by refusing at runtime for an absent anchor,
     * because refusing would gate the §13.5 rehearsal on configuration only a
     * production deploy can supply.
     */
    #[Test]
    public function every_shipped_environment_carries_non_matching_production_anchors(): void
    {
        $guard = new HistoricImportProductionGuard($this->app, app(HistoricImportResourceIdentity::class));

        foreach (['database', 'storage'] as $role) {
            $anchor = config("church.historic_corpus.production_{$role}_anchor");

            $this->assertIsString($anchor, "No production {$role} anchor is configured for this environment.");
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', trim($anchor));
        }

        $this->assertNull($guard->anchorConfigurationError());
        $this->assertFalse(
            $guard->guardsCurrentEnvironment(),
            'A test run must observe neither production anchor.',
        );
        $this->assertFalse($guard->matchesProductionStorageAnchor());
    }

    /**
     * HIR0 step 2, the artifact half: a signed approval or release
     * authorisation must never be committed. Both are private operator
     * artifacts, and one reaching the repository would make the fail-closed
     * default defeatable by checkout alone.
     */
    #[Test]
    public function no_signed_approval_or_release_authorisation_is_committed(): void
    {
        $formats = [
            'crockenhill-historic-import-approval',
            'crockenhill-historic-release-authorisation',
        ];
        $tracked = array_filter(explode("\n", (string) shell_exec('cd '.escapeshellarg(base_path()).' && git ls-files "*.json"')));

        // Without this the scan would silently cover nothing if git were
        // unavailable, and a committed authorisation would pass unnoticed.
        $this->assertNotEmpty($tracked, 'The committed-artifact scan found no tracked JSON files to check.');

        foreach ($tracked as $relative) {
            $contents = (string) @file_get_contents(base_path(trim($relative)));

            foreach ($formats as $format) {
                $this->assertStringNotContainsString(
                    $format,
                    $contents,
                    "{$relative} contains a {$format} artifact; production authority must not be committed.",
                );
            }
        }

        $this->addToAssertionCount(1);
    }

    /**
     * HIR0 step 6. `AGENTS.md` requires every new one-shot Artisan command to
     * declare its deletion trigger in its class docblock, and G9/WP10 is the
     * gate that eventually acts on those triggers. Nothing enforced the rule, so
     * a command could join the historic programme and quietly become permanent
     * product surface.
     *
     * Scope is the `historic-import:` namespace: every command in it exists only
     * for this one-time operation, so the rule applies to all of them without
     * needing a heuristic for what counts as a one-shot elsewhere.
     */
    #[Test]
    public function every_historic_import_one_shot_declares_its_deletion_trigger(): void
    {
        $undeclared = [];
        $scanned = 0;

        foreach (Finder::create()->files()->in(app_path('Console/Commands'))->name('*.php') as $file) {
            if (preg_match("/signature\s*=\s*'historic-import:/", $file->getContents()) !== 1) {
                continue;
            }

            $class = 'App\\Console\\Commands\\'.$file->getBasename('.php');

            if (! class_exists($class)) {
                continue;
            }

            $scanned++;
            $docblock = (string) (new ReflectionClass($class))->getDocComment();

            if (preg_match('/\bDelete\s+(after|once|alongside|when)\b/i', $docblock) !== 1) {
                $undeclared[] = $file->getBasename('.php');
            }
        }

        // A broken discovery regex would otherwise make this pass by scanning
        // nothing at all.
        $this->assertGreaterThanOrEqual(6, $scanned, 'The one-shot scan found too few historic-import commands.');
        $this->assertSame(
            [],
            $undeclared,
            'These historic-import one-shots declare no deletion trigger, so G9/WP10 cannot retire them: '
            .implode(', ', $undeclared),
        );
    }
}
