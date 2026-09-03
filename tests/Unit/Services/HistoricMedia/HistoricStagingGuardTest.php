<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Services\HistoricMedia\HistoricStagingGuard;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricStagingGuardTest extends TestCase
{
    #[Test]
    public function it_accepts_media_disks_pointed_at_the_private_staging_disk(): void
    {
        $this->configure('historic_staging', 'historic_staging', 'historic_staging');

        $this->expectNotToPerformAssertions();

        app(HistoricStagingGuard::class)->assertLocalProcessingIsIsolated();
    }

    #[Test]
    public function it_refuses_to_process_historic_media_onto_the_production_media_disk(): void
    {
        $this->configure('historic_staging', 'do_spaces', 'do_spaces');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Historic processing would write sermon_disk to the 'do_spaces' disk.");

        app(HistoricStagingGuard::class)->assertLocalProcessingIsIsolated();
    }

    #[Test]
    public function it_refuses_when_only_the_transcript_disk_escapes_staging(): void
    {
        $this->configure('historic_staging', 'historic_staging', 'do_spaces');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Historic processing would write transcript_disk');

        app(HistoricStagingGuard::class)->assertLocalProcessingIsIsolated();
    }

    #[Test]
    public function it_refuses_a_publicly_served_disk_as_private_staging(): void
    {
        $this->configure('public', 'public', 'public');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Historic staging disk 'public' is publicly served");

        app(HistoricStagingGuard::class)->assertLocalProcessingIsIsolated();
    }

    #[Test]
    public function it_refuses_to_export_assets_staged_on_a_served_disk(): void
    {
        config()->set('media-processing.storage.historic_staging_disk', 'do_spaces');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Historic staging disk 'do_spaces' is publicly served");

        app(HistoricStagingGuard::class)->assertExportSourcesAreStaged(['do_spaces']);
    }

    #[Test]
    public function it_accepts_export_assets_sourced_only_from_the_staging_disk(): void
    {
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');

        $this->expectNotToPerformAssertions();

        app(HistoricStagingGuard::class)->assertExportSourcesAreStaged(['historic_staging']);
    }

    #[Test]
    public function it_refuses_to_export_a_source_from_a_non_staging_disk(): void
    {
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("source disk 'local' is not the configured staging disk 'historic_staging'");

        app(HistoricStagingGuard::class)->assertExportSourcesAreStaged(['local']);
    }

    #[Test]
    public function it_refuses_when_no_staging_disk_is_configured(): void
    {
        $this->configure('', 'local', 'local');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No historic staging disk is configured.');

        app(HistoricStagingGuard::class)->assertLocalProcessingIsIsolated();
    }

    #[Test]
    public function it_derives_a_private_per_batch_root_from_the_approved_plan(): void
    {
        $this->configure('historic_staging', 'historic_staging', 'historic_staging');

        $context = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            str_repeat('a', 64),
            str_repeat('b', 64),
        );

        self::assertSame('historic-batches/'.str_repeat('b', 64), $context->batchRoot);
        self::assertSame(str_repeat('a', 64), $context->manifestHash);
        self::assertSame(str_repeat('b', 64), $context->planHash);
    }

    #[Test]
    public function it_refuses_a_private_alias_that_resolves_to_public_storage(): void
    {
        config([
            'filesystems.disks.staging_alias' => config('filesystems.disks.public'),
            'filesystems.disks.staging_alias.visibility' => 'private',
            'filesystems.disks.staging_alias.url' => null,
            'media-processing.storage.historic_staging_disk' => 'staging_alias',
            'media-processing.storage.sermon_disk' => 'staging_alias',
            'media-processing.storage.transcript_disk' => 'staging_alias',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("resolves to publicly served disk 'public'");

        app(HistoricStagingGuard::class)->assertLocalProcessingIsIsolated();
    }

    #[Test]
    public function it_refuses_a_worker_with_a_different_staging_root_than_the_approved_context(): void
    {
        $this->configure('historic_staging', 'historic_staging', 'historic_staging');
        $guard = app(HistoricStagingGuard::class);
        $context = $guard->contextForApprovedPlan(str_repeat('a', 64), str_repeat('b', 64));

        config(['filesystems.disks.historic_staging.root' => storage_path('app/private/other-historic-staging')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restart workers with the approved storage configuration.');

        $guard->activate($context);
    }

    #[Test]
    public function it_activates_the_same_context_again_when_a_prior_activation_leaked_its_batch_root(): void
    {
        /**
         * The 2026-09-03 wedge: `storageIdentity()` fingerprints the live staging
         * root, but `activate()` mutates that root to append the batch directory.
         * If a prior activation was never restored, the next activate() compared a
         * batch-rooted fingerprint against the context's base-rooted one and threw
         * a misleading "restart workers" error, stranding the run.
         */
        $this->configure('historic_staging', 'historic_staging', 'historic_staging');
        $guard = app(HistoricStagingGuard::class);
        $context = $guard->contextForApprovedPlan(str_repeat('a', 64), str_repeat('b', 64));

        $baseRoot = (string) config('filesystems.disks.historic_staging.root');

        // Activate and deliberately do NOT restore, reproducing the leak.
        $guard->activate($context);
        $this->assertNotSame($baseRoot, (string) config('filesystems.disks.historic_staging.root'));

        $guard->activate($context);

        $this->assertSame(
            $this->appendedBatchRoot($baseRoot, $context->batchRoot),
            (string) config('filesystems.disks.historic_staging.root'),
            'A repeated activation must re-apply the batch root to the pristine base, not compound onto the leaked root.'
        );
    }

    #[Test]
    public function it_activates_a_context_correctly_from_a_fresh_instance_after_a_prior_instance_leaked(): void
    {
        /**
         * The 2026-09-03 wedge, cross-instance form — reproduced live during
         * bulk retries the same day. `HistoricStagingGuard` is not bound
         * scoped/singleton, so a fresh instance is constructed for every job
         * (the registry that holds it *is* scoped, and Laravel resets scoped
         * bindings between queue jobs). If one job's guard activates and never
         * restores — an unbalanced depth, an unusual failure path, anything —
         * the live `config()` state is left batch-rooted, because config
         * mutations are process-global and outlive any one scoped instance.
         * The *next* job's guard is a brand-new object with an empty baseline
         * cache, so capturing the baseline per-instance (as the first fix did)
         * makes that fresh guard treat the already-dirty root as pristine and
         * reject a perfectly valid context. The baseline must be shared across
         * every guard instance for the life of the process, not just within one.
         */
        $this->configure('historic_staging', 'historic_staging', 'historic_staging');
        $leakedGuard = app(HistoricStagingGuard::class);
        $context = $leakedGuard->contextForApprovedPlan(str_repeat('a', 64), str_repeat('b', 64));

        $leakedGuard->activate($context);
        // Deliberately no restore() — simulates the prior job's leak.

        $freshGuard = app(HistoricStagingGuard::class);
        $this->assertNotSame($leakedGuard, $freshGuard);

        // No exception: a fresh instance must not reject a valid context just
        // because a different instance already left the disk batch-rooted.
        $freshGuard->activate($context);
        $this->addToAssertionCount(1);
    }

    /**
     * D7 (2026-09-03) decided to instrument the leak rather than chase it: the
     * static baseline already absorbs it, but activate/deactivate went out of
     * balance six times in three runs in ten minutes with no evidence naming the
     * path. This is the signature the registry logs on — a staging root still
     * carrying a batch directory when nothing should be active — and it has to
     * carry both paths, since the batch root is what identifies the run.
     */
    #[Test]
    public function it_reports_a_leaked_activation_as_a_divergence_from_the_pristine_baseline(): void
    {
        $this->configure('historic_staging', 'historic_staging', 'historic_staging');
        $guard = app(HistoricStagingGuard::class);
        $context = $guard->contextForApprovedPlan(str_repeat('a', 64), str_repeat('b', 64));
        $baseRoot = (string) config('filesystems.disks.historic_staging.root');

        $this->assertNull($guard->leakedActivationEvidence('historic_staging'));

        // Activate and deliberately do NOT restore, reproducing the leak.
        $guard->activate($context);

        $this->assertSame([
            'baseline_root' => $baseRoot,
            'live_root' => $this->appendedBatchRoot($baseRoot, $context->batchRoot),
        ], $guard->leakedActivationEvidence('historic_staging'));

        $this->assertSame(
            $this->appendedBatchRoot($baseRoot, $context->batchRoot),
            $guard->liveRoot('historic_staging'),
        );
    }

    #[Test]
    public function it_reports_no_leak_for_a_disk_that_has_never_been_activated(): void
    {
        $this->configure('historic_staging', 'historic_staging', 'historic_staging');

        $this->assertNull(app(HistoricStagingGuard::class)->leakedActivationEvidence('historic_staging'));
        $this->assertSame('', app(HistoricStagingGuard::class)->liveRoot('no_such_disk'));
    }

    private function appendedBatchRoot(string $base, string $batchRoot): string
    {
        return rtrim($base, '/').'/'.$batchRoot;
    }

    private function configure(string $staging, string $sermon, string $transcript): void
    {
        config([
            'media-processing.storage.historic_staging_disk' => $staging,
            'media-processing.storage.sermon_disk' => $sermon,
            'media-processing.storage.transcript_disk' => $transcript,
        ]);
    }
}
