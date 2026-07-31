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
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Historic staging disk 'do_spaces' is publicly served");

        app(HistoricStagingGuard::class)->assertExportSourcesArePrivate(['historic_staging', 'do_spaces']);
    }

    #[Test]
    public function it_accepts_export_assets_staged_on_private_disks(): void
    {
        $this->expectNotToPerformAssertions();

        app(HistoricStagingGuard::class)->assertExportSourcesArePrivate(['historic_staging', 'local']);
    }

    #[Test]
    public function it_refuses_when_no_staging_disk_is_configured(): void
    {
        $this->configure('', 'local', 'local');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No historic staging disk is configured.');

        app(HistoricStagingGuard::class)->assertLocalProcessingIsIsolated();
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
