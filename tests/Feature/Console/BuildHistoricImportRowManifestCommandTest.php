<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Sermon;
use App\Services\Import\HistoricImportResourceIdentity;
use App\Services\Import\HistoricImportRowManifest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HIR5's operator entry point: one read-only pass over a disposable restore,
 * written to an artifact the recovery gate later opens and compares.
 */
class BuildHistoricImportRowManifestCommandTest extends TestCase
{
    use DatabaseTransactions;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = (string) realpath(sys_get_temp_dir()).'/hir5-manifest-'.Str::uuid().'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_writes_a_manifest_of_what_the_connection_actually_holds(): void
    {
        Sermon::factory()->count(2)->create();

        $this->artisan('historic-import:row-manifest', ['--output' => $this->path])
            ->expectsOutputToContain('Connection anchor: '.app(HistoricImportResourceIdentity::class)->databaseAnchor())
            ->assertSuccessful();

        $manifest = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(HistoricImportRowManifest::Format, $manifest['format']);
        $this->assertSame(2, $manifest['tables']['sermons']['row_count']);
    }

    /**
     * The recovery gate refuses a manifest whose bytes moved, so overwriting one
     * in place would be a way to invalidate already-signed evidence.
     */
    #[Test]
    public function it_refuses_to_overwrite_an_existing_manifest(): void
    {
        file_put_contents($this->path, 'an earlier manifest');

        $this->artisan('historic-import:row-manifest', ['--output' => $this->path])
            ->expectsOutputToContain('must be created at a new path')
            ->assertFailed();

        $this->assertSame('an earlier manifest', file_get_contents($this->path));
    }

    #[Test]
    public function it_requires_an_absolute_output_path(): void
    {
        $this->artisan('historic-import:row-manifest', ['--output' => 'manifest.json'])
            ->expectsOutput('A row manifest requires an absolute --output path.')
            ->assertFailed();
    }
}
