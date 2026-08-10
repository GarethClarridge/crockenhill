<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Import\HistoricSourceAcquisitionVerifier;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerifyHistoricSourceAcquisitionCommandTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach ($this->roots as $root) {
            $this->removeDirectory($root);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_verifies_two_signed_complete_copies_and_inventories_hidden_unsupported_and_link_paths(): void
    {
        [$evidence, $working, $dispositions] = $this->copies();
        $custody = $this->custody($evidence, $working, $dispositions);
        $custodyPath = $this->jsonFile($custody);
        $report = storage_path('app/private/source-acquisition-'.uniqid().'.json');
        $this->files[] = $report;
        config()->set('media-processing.historic_import.evidence_signing_key', 'test-signing-key');

        $this->artisan('historic-import:verify-source-acquisition', [
            'custody' => $custodyPath,
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--report' => $report,
        ])->assertSuccessful();

        $result = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
        $paths = array_column($result['copies']['working']['entries'], 'relative_path');

        $this->assertSame(['.hidden-sidecar', 'archive', 'archive/recording.avi', 'recording-link'], $paths);
        $this->assertSame(4, $result['copies']['working']['path_count']);
        $this->assertSame('hmac-sha256', $result['signature']['algorithm']);
    }

    #[Test]
    public function it_hard_stops_an_unclean_scan_before_writing_a_report(): void
    {
        [$evidence, $working, $dispositions] = $this->copies();
        $custody = $this->custody($evidence, $working, $dispositions);
        $custody['malware_scan']['status'] = 'quarantined';
        $custody['signature']['digest'] = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($custody, ['signature' => true])),
            'test-signing-key',
        );
        $report = storage_path('app/private/source-acquisition-'.uniqid().'.json');
        $this->files[] = $report;
        config()->set('media-processing.historic_import.evidence_signing_key', 'test-signing-key');

        $this->artisan('historic-import:verify-source-acquisition', [
            'custody' => $this->jsonFile($custody),
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--report' => $report,
        ])
            ->expectsOutput('A clean, checksummed malware scan is required before processing.')
            ->assertFailed();

        $this->assertFileDoesNotExist($report);
    }

    /** @return array{0:string,1:string,2:array<string, array{disposition:string,xattrs:array<string,string>}>} */
    private function copies(): array
    {
        $base = sys_get_temp_dir().'/historic-source-'.uniqid();
        $evidence = $base.'-evidence';
        $working = $base.'-working';
        $this->roots = [$evidence, $working];

        foreach ([$evidence, $working] as $root) {
            mkdir($root.'/archive', 0755, true);
            file_put_contents($root.'/.hidden-sidecar', 'metadata');
            file_put_contents($root.'/archive/recording.avi', 'historic-video');
            symlink('archive/recording.avi', $root.'/recording-link');

            foreach ([$root, $root.'/archive', $root.'/.hidden-sidecar', $root.'/archive/recording.avi'] as $path) {
                touch($path, 1_700_000_000);
            }
        }

        return [$evidence, $working, [
            '.hidden-sidecar' => ['disposition' => 'preserve_sidecar', 'xattrs' => []],
            'archive' => ['disposition' => 'traverse', 'xattrs' => []],
            'archive/recording.avi' => ['disposition' => 'include_unsupported_media', 'xattrs' => []],
            'recording-link' => ['disposition' => 'materialize_in_working_copy', 'xattrs' => []],
        ]];
    }

    /**
     * @param  array<string, array{disposition:string,xattrs:array<string,string>}>  $dispositions
     * @return array<string, mixed>
     */
    private function custody(string $evidence, string $working, array $dispositions): array
    {
        $verifier = app(HistoricSourceAcquisitionVerifier::class);
        $custody = [
            'format' => 'crockenhill-historic-source-custody',
            'version' => 1,
            'batch_key' => 'historic-source-test',
            'physical_source' => [
                'device_identity' => 'device-serial-hash',
                'volume_identity' => 'volume-uuid',
                'filesystem' => 'apfs',
                'health_report_sha256' => str_repeat('a', 64),
                'read_error_count' => 0,
                'mount' => [
                    'read_only' => true,
                    'write_probe_failed' => true,
                    'noexec' => true,
                    'nosuid' => true,
                    'nodev' => true,
                ],
            ],
            'capacity_plan' => [
                'source_bytes' => strlen('metadata') + strlen('historic-video'),
                'evidence_available_bytes' => 1_000_000,
                'working_available_bytes' => 1_000_000,
                'temporary_required_bytes' => 100_000,
                'staging_required_bytes' => 100_000,
                'rollback_required_bytes' => 100_000,
                'approved_contingency_percent' => 25,
                'accepted' => true,
                'planned_by' => 'archive operator',
                'planned_at' => '2026-08-09T09:00:00+00:00',
            ],
            'copies' => [
                'evidence' => [
                    'storage_identity' => 'offline-evidence-vault',
                    'protected_read_only' => true,
                    'inventory_hash' => $verifier->inventory($evidence, $dispositions)['inventory_hash'],
                ],
                'working' => [
                    'storage_identity' => 'processing-array',
                    'protected_read_only' => true,
                    'inventory_hash' => $verifier->inventory($working, $dispositions)['inventory_hash'],
                ],
            ],
            'malware_scan' => [
                'status' => 'clean',
                'engine' => 'clamav',
                'definitions_at' => '2026-08-09T10:00:00+00:00',
                'scanned_at' => '2026-08-09T11:00:00+00:00',
                'report_sha256' => str_repeat('b', 64),
            ],
            'retention' => [
                'owner' => 'archive custodian',
                'retain_until' => '2027-02-09',
                'destruction_requires_acceptance' => true,
            ],
            'dispositions' => $dispositions,
            'signature' => ['algorithm' => 'hmac-sha256', 'key_id' => 'test-key', 'digest' => ''],
        ];
        $custody['signature']['digest'] = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($custody, ['signature' => true])),
            'test-signing-key',
        );

        return $custody;
    }

    /** @param array<string, mixed> $value */
    private function jsonFile(array $value): string
    {
        $path = sys_get_temp_dir().'/historic-source-custody-'.uniqid().'.json';
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR));
        $this->files[] = $path;

        return $path;
    }

    private function removeDirectory(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($root);
    }
}
