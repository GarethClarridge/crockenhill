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

    private function configure(string $staging, string $sermon, string $transcript): void
    {
        config([
            'media-processing.storage.historic_staging_disk' => $staging,
            'media-processing.storage.sermon_disk' => $sermon,
            'media-processing.storage.transcript_disk' => $transcript,
        ]);
    }
}
