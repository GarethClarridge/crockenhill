<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Contracts\HistoricSourceFilesystemInspector;
use App\Services\Import\HistoricSourceAcquisitionVerifier;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHistoricSourceFilesystemInspector;
use Tests\Support\HistoricSourceCopyFixture;
use Tests\TestCase;

/**
 * The producer half of HIR4's acquisition gate.
 *
 * {@see HistoricSourceAcquisitionVerifier} refuses any path with no adjudicated
 * disposition, so the operator needs the complete path list *before* the
 * decisions can be made. Drafting is read-only and deliberately leaves every
 * disposition null: this command enumerates, it never decides.
 */
class DraftHistoricSourceDispositionsCommandTest extends TestCase
{
    private HistoricSourceCopyFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(storage_path('app/private'));

        $this->fixture = new HistoricSourceCopyFixture;
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanUp();

        parent::tearDown();
    }

    #[Test]
    public function it_drafts_every_hidden_nested_and_linked_path_with_no_disposition_decided(): void
    {
        [$evidence] = $this->fixture->copies();
        $worksheet = $this->fixture->reservedPath('worksheet');

        $this->artisan('historic-import:draft-source-dispositions', [
            'copy' => $evidence,
            '--worksheet' => $worksheet,
        ])->assertSuccessful();

        $drafted = json_decode((string) file_get_contents($worksheet), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('crockenhill-historic-source-disposition-worksheet', $drafted['format']);
        $this->assertSame(4, $drafted['path_count']);
        $this->assertSame(
            ['.hidden-sidecar', 'archive', 'archive/recording.avi', 'recording-link'],
            array_keys($drafted['paths']),
        );

        foreach ($drafted['paths'] as $relative => $path) {
            $this->assertNull($path['disposition'], "{$relative} arrived with a disposition already decided.");
        }

        $this->assertSame('file', $drafted['paths']['.hidden-sidecar']['observed']['type']);
        $this->assertSame('directory', $drafted['paths']['archive']['observed']['type']);
        $this->assertSame('symlink', $drafted['paths']['recording-link']['observed']['type']);
        $this->assertSame('archive/recording.avi', $drafted['paths']['recording-link']['observed']['link_target']);
        $this->assertSame([], $drafted['disposition_reasons']);
    }

    /**
     * The guard that keeps the producer and the gate speaking about one tree.
     *
     * Drafting cannot call {@see HistoricSourceAcquisitionVerifier::inventory()}
     * — that method refuses a path with no disposition, and having no
     * dispositions yet is the entire reason to draft. So it walks the tree
     * itself, and this asserts the two walks enumerate exactly the same paths.
     * Without it, a divergence would only surface on the acquisition host with
     * the drive already connected.
     */
    #[Test]
    public function it_enumerates_exactly_the_paths_the_acquisition_gate_inventories(): void
    {
        [$evidence] = $this->fixture->copies(awkwardNames: true);
        $worksheet = $this->fixture->reservedPath('worksheet');

        $this->artisan('historic-import:draft-source-dispositions', [
            'copy' => $evidence,
            '--worksheet' => $worksheet,
        ])->assertSuccessful();

        $drafted = json_decode((string) file_get_contents($worksheet), true, flags: JSON_THROW_ON_ERROR);
        $dispositions = array_map(
            static fn (): array => ['disposition' => 'preserve', 'xattrs' => []],
            $drafted['paths'],
        );

        $inventory = app(HistoricSourceAcquisitionVerifier::class)->inventory($evidence, $dispositions);

        $this->assertSame(
            array_keys($drafted['paths']),
            array_column($inventory['entries'], 'relative_path'),
        );
    }

    /**
     * Workstream D2 requires read errors to be recorded, and the gate refuses
     * any inventory containing one. Surfacing them here means the operator
     * learns about an unreadable path while the decision is still "re-copy the
     * source", not after the custody artifact has been signed.
     */
    #[Test]
    public function it_fails_and_names_a_path_the_copy_cannot_read(): void
    {
        [$evidence] = $this->fixture->copies();
        symlink('nowhere/at/all', $evidence.'/dangling-link');
        $worksheet = $this->fixture->reservedPath('worksheet');

        $this->artisan('historic-import:draft-source-dispositions', [
            'copy' => $evidence,
            '--worksheet' => $worksheet,
        ])
            ->expectsOutputToContain('dangling-link')
            ->assertFailed();

        $this->assertFileDoesNotExist($worksheet);
    }

    /**
     * A case/Unicode collision is not a naming inconvenience: the gate throws on
     * it, and on a drive written by two operating systems it is a live risk. The
     * draft is the first read of the tree, so it is where the collision should
     * be found.
     */
    #[Test]
    public function it_fails_on_a_case_or_unicode_normalisation_collision(): void
    {
        [$evidence] = $this->fixture->copies();

        if (file_exists($evidence.'/ARCHIVE')) {
            $this->markTestSkipped('This filesystem is case-insensitive, so the collision cannot be created.');
        }

        mkdir($evidence.'/ARCHIVE');
        $worksheet = $this->fixture->reservedPath('worksheet');

        $this->artisan('historic-import:draft-source-dispositions', [
            'copy' => $evidence,
            '--worksheet' => $worksheet,
        ])
            ->expectsOutputToContain('collision')
            ->assertFailed();

        $this->assertFileDoesNotExist($worksheet);
    }

    #[Test]
    public function it_refuses_to_overwrite_an_existing_worksheet(): void
    {
        [$evidence] = $this->fixture->copies();
        $worksheet = $this->fixture->reservedPath('worksheet');
        file_put_contents($worksheet, '{"already":"drafted"}');

        $this->artisan('historic-import:draft-source-dispositions', [
            'copy' => $evidence,
            '--worksheet' => $worksheet,
        ])->assertFailed();

        $this->assertSame('{"already":"drafted"}', file_get_contents($worksheet));
    }

    #[Test]
    public function it_keeps_the_worksheet_below_private_storage(): void
    {
        [$evidence] = $this->fixture->copies();

        $this->artisan('historic-import:draft-source-dispositions', [
            'copy' => $evidence,
            '--worksheet' => '/tmp/escaped-worksheet.json',
        ])->assertFailed();

        $this->assertFileDoesNotExist('/tmp/escaped-worksheet.json');
    }

    /**
     * Drafting reads; it never writes into a copy that acquisition has just
     * proved non-writable.
     */
    #[Test]
    public function it_does_not_write_into_the_copy_it_reads(): void
    {
        [$evidence] = $this->fixture->copies();
        $this->app->instance(
            HistoricSourceFilesystemInspector::class,
            (new FakeHistoricSourceFilesystemInspector)->root($evidence, 'evidence-vault'),
        );
        $before = $this->fixture->treeSignature($evidence);

        $this->artisan('historic-import:draft-source-dispositions', [
            'copy' => $evidence,
            '--worksheet' => $this->fixture->reservedPath('worksheet'),
        ])->assertSuccessful();

        $this->assertSame($before, $this->fixture->treeSignature($evidence));
    }
}
