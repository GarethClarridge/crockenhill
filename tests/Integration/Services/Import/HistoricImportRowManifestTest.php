<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Import;

use App\Models\Sermon;
use App\Services\Import\HistoricImportResourceIdentity;
use App\Services\Import\HistoricImportRowManifest;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * HIR5 item 6: the one read-only implementation both disposable restores are
 * read through.
 *
 * Version 1 recovery evidence carried a `table_row_manifest_sha256` per restore
 * and checked the two strings were equal, which two copies of one typed
 * placeholder satisfy. What this class produces is read from the database
 * itself, and what it parses is checked against its own membership digest — so a
 * manifest edited after production no longer matches the producer that wrote it.
 */
class HistoricImportRowManifestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_manifest_counts_the_rows_that_are_actually_there(): void
    {
        Sermon::factory()->count(3)->create();

        $manifest = $this->manifests()->forConnection();

        $this->assertSame(HistoricImportRowManifest::Format, $manifest['format']);
        $this->assertSame(HistoricImportRowManifest::Version, $manifest['version']);
        $this->assertSame(3, $manifest['tables']['sermons']['row_count']);
        $this->assertSame(count($manifest['tables']), $manifest['table_count']);
        $this->assertSame(
            app(HistoricImportResourceIdentity::class)->databaseAnchor(),
            $manifest['connection_anchor'],
            'A manifest records which database it was read from, so a restore cannot be verified against production.',
        );
    }

    /** The producer's output is what the verifier's parser accepts. */
    #[Test]
    public function a_manifest_this_implementation_produced_parses_and_compares_equal_to_itself(): void
    {
        Sermon::factory()->count(2)->create();
        $manifests = $this->manifests();
        $manifest = $manifests->forConnection();
        $parsed = $manifests->parse((string) json_encode($manifest, JSON_THROW_ON_ERROR), 'on_host');

        $manifests->assertEqualMembership($parsed, $parsed);

        $this->assertSame($manifest['membership_sha256'], $parsed['membership_sha256']);
    }

    #[Test]
    public function a_manifest_edited_away_from_its_membership_digest_is_refused(): void
    {
        $manifests = $this->manifests();
        $manifest = $manifests->forConnection();
        $manifest['tables']['sermons']['row_count'] = 4_096;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match its own membership digest');

        $manifests->parse((string) json_encode($manifest, JSON_THROW_ON_ERROR), 'off_host');
    }

    #[Test]
    public function a_document_from_another_producer_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not produced by this implementation');

        $this->manifests()->parse('{"format":"something-else","version":1}', 'on_host');
    }

    /**
     * Exact membership, and named. A restore that came back short is an
     * incident, and "the digests differ" is not a starting point for one.
     */
    #[Test]
    public function restores_holding_different_row_counts_name_the_table_that_disagrees(): void
    {
        $manifests = $this->manifests();
        $onHost = $this->manifest(['sermons' => ['row_count' => 40, 'columns_sha256' => str_repeat('a', 64)]]);
        $offHost = $this->manifest(['sermons' => ['row_count' => 39, 'columns_sha256' => str_repeat('a', 64)]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disagree about table sermons: 40 rows on-host against 39 off-host');

        $manifests->assertEqualMembership($onHost, $offHost);
    }

    #[Test]
    public function restores_holding_different_tables_name_both_sides(): void
    {
        $manifests = $this->manifests();
        $onHost = $this->manifest(['sermons' => ['row_count' => 40, 'columns_sha256' => str_repeat('a', 64)]]);
        $offHost = $this->manifest(['songs' => ['row_count' => 40, 'columns_sha256' => str_repeat('a', 64)]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('On-host only: sermons. Off-host only: songs.');

        $manifests->assertEqualMembership($onHost, $offHost);
    }

    /**
     * A schema change with an unchanged row count still moves the manifest: a
     * restore that came back with a different column shape is not the same
     * database.
     */
    #[Test]
    public function restores_holding_different_column_shapes_disagree(): void
    {
        $manifests = $this->manifests();
        $onHost = $this->manifest(['sermons' => ['row_count' => 40, 'columns_sha256' => str_repeat('a', 64)]]);
        $offHost = $this->manifest(['sermons' => ['row_count' => 40, 'columns_sha256' => str_repeat('b', 64)]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disagree about table sermons');

        $manifests->assertEqualMembership($onHost, $offHost);
    }

    /**
     * @param  array<string, array{row_count: int, columns_sha256: string}>  $tables
     * @return array<string, mixed>
     */
    private function manifest(array $tables): array
    {
        return [
            'format' => HistoricImportRowManifest::Format,
            'version' => HistoricImportRowManifest::Version,
            'connection_anchor' => str_repeat('1', 64),
            'generated_at' => '2026-08-12T12:00:00+00:00',
            'table_count' => count($tables),
            'tables' => $tables,
            'membership_sha256' => CanonicalJson::hash($tables),
        ];
    }

    private function manifests(): HistoricImportRowManifest
    {
        return app(HistoricImportRowManifest::class);
    }
}
