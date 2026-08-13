<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Contracts\HistoricSourceFilesystemInspector;
use App\Services\Import\HistoricSourceAcquisitionVerifier;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHistoricSourceFilesystemInspector;
use Tests\TestCase;

/**
 * HIR4. The acquisition gate proves custody from observed storage facts, not
 * from signed claims alone.
 *
 * The mount and write-protection halves are supplied by an injected observation
 * where this host cannot produce them — it has one writable filesystem and runs
 * as root, so it can neither mount a second failure domain nor make a directory
 * unwritable. Everything the inventory checks is real: real temporary trees,
 * real links, real hard links, real bytes. The one case that must run against
 * the real inspector is the finding itself, because its whole point is that the
 * actual disk refuses.
 */
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
        [$evidence, $working, $dispositions] = $this->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
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
        $this->assertSame(HistoricSourceAcquisitionVerifier::Version, $result['version']);

        /**
         * The disposition the review found unenforced: the evidence copy keeps
         * its documented link and the working copy holds the bytes. Their
         * physical inventories differ, deliberately, and their logical byte sets
         * are equal — which is what proves the copies hold the same content
         * without pretending the two objects are the same object.
         */
        $this->assertTrue(is_link($evidence.'/recording-link'));
        $this->assertTrue(is_file($working.'/recording-link') && ! is_link($working.'/recording-link'));
        $this->assertNotSame(
            $result['copies']['evidence']['inventory_hash'],
            $result['copies']['working']['inventory_hash'],
        );
        $this->assertNotSame(
            $result['inspector']['evidence']['failure_domain'],
            $result['inspector']['working']['failure_domain'],
        );
    }

    /**
     * HIR0 red test for review finding 2 (High), owned by package **HIR4**.
     *
     * The fixture is deliberately the one
     * {@see self::it_verifies_two_signed_complete_copies_and_inventories_hidden_unsupported_and_link_paths()}
     * already passes with, because that is the finding: two sibling directories
     * on one device, both writable, with `recording-link` still a symlink
     * despite its `materialize_in_working_copy` disposition, are accepted as
     * "two signed complete copies". The only independence check is that
     * `realpath()` differs; `storage_identity` and `protected_read_only` are
     * signed claims nobody compares with the disk.
     *
     * A single filesystem loss or a later mutation therefore defeats the
     * evidence copy and the working copy together, while the acquisition gate
     * reports success.
     *
     * This deliberately contradicts that acceptance test. It is superseded
     * evidence: HIR4 rebuilds its fixture as two genuinely independent
     * protected copies rather than deleting the case.
     *
     * @see docs/reviews/historic-import-commit-review-2026-08-12.md finding 2
     * @see docs/plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md §10 (HIR4)
     */
    #[Test]
    #[Group('hir-red')]
    public function it_refuses_two_writable_copies_that_share_one_failure_domain(): void
    {
        [$evidence, $working, $dispositions] = $this->copies();

        // The facts the verifier never observes, asserted here so the refusal
        // below cannot pass for an unrelated reason.
        $this->assertSame(
            (int) (stat($evidence)['dev'] ?? -1),
            (int) (stat($working)['dev'] ?? -2),
            'The fixture must put both copies on one device for this to be the finding.',
        );
        $this->assertTrue(is_writable($evidence), 'The evidence copy must be writable for this to be the finding.');
        $this->assertTrue(is_link($working.'/recording-link'), 'The working copy must leave the link unmaterialised.');

        $custody = $this->custody($evidence, $working, $dispositions);
        $report = storage_path('app/private/source-acquisition-'.uniqid().'.json');
        $this->files[] = $report;
        config()->set('media-processing.historic_import.evidence_signing_key', 'test-signing-key');

        $this->artisan('historic-import:verify-source-acquisition', [
            'custody' => $this->jsonFile($custody),
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--report' => $report,
        ])->assertFailed();

        $this->assertFileDoesNotExist($report);
    }

    #[Test]
    public function it_hard_stops_an_unclean_scan_before_writing_a_report(): void
    {
        [$evidence, $working, $dispositions] = $this->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
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

    /**
     * The independence check is on the failure domain, not the path. Two roots
     * with different canonical paths and different declared storage identities
     * are still one copy if they sit on one mounted device.
     */
    #[Test]
    public function two_roots_on_one_mount_are_one_copy_however_they_are_labelled(): void
    {
        [$evidence, $working, $dispositions] = $this->copies(materialiseWorkingLink: true);
        $this->app->instance(
            HistoricSourceFilesystemInspector::class,
            (new FakeHistoricSourceFilesystemInspector)
                ->root($evidence, 'one-array')
                ->root($working, 'one-array'),
        );

        $this->assertRefusal(
            $evidence,
            $working,
            $dispositions,
            'share one failure domain',
        );
    }

    /** A copy claimed protected must actually refuse a write. */
    #[Test]
    public function a_writable_copy_cannot_be_claimed_protected(): void
    {
        [$evidence, $working, $dispositions] = $this->copies(materialiseWorkingLink: true);
        $this->app->instance(
            HistoricSourceFilesystemInspector::class,
            (new FakeHistoricSourceFilesystemInspector)
                ->root($evidence, 'evidence-vault', readOnly: false, writeProbeFailed: false, mountOptions: ['rw'])
                ->root($working, 'processing-array'),
        );

        $this->assertRefusal($evidence, $working, $dispositions, 'a write probe succeeded against it');
    }

    /** The claimed failure domain is compared, not just recorded. */
    #[Test]
    public function a_recorded_failure_domain_that_does_not_match_the_disk_is_refused(): void
    {
        [$evidence, $working, $dispositions] = $this->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);

        $this->assertRefusal(
            $evidence,
            $working,
            $dispositions,
            'recorded failure domain does not match the observed one',
            static function (array $custody): array {
                $custody['copies']['evidence']['failure_domain'] = str_repeat('7', 64);

                return $custody;
            },
        );
    }

    /**
     * The disposition the review found unenforced. The custody artifact said
     * the bytes were in the working copy; they were still a link.
     */
    #[Test]
    public function a_materialise_disposition_that_is_still_a_link_is_refused(): void
    {
        [$evidence, $working, $dispositions] = $this->copies();
        $this->observeIndependentProtectedCopies($evidence, $working);

        $this->assertRefusal($evidence, $working, $dispositions, 'is still a symlink in the working copy');
    }

    /**
     * A copy that depends on something outside itself is not a copy. Absolute,
     * escaping and externally targeted links are all the same failure.
     */
    #[Test]
    #[DataProvider('unsafeLinkTargets')]
    public function a_link_that_leaves_its_copy_is_refused(string $target, string $expected): void
    {
        [$evidence, $working, $dispositions] = $this->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        unlink($evidence.'/recording-link');
        symlink($target, $evidence.'/recording-link');

        $this->assertRefusal($evidence, $working, $dispositions, $expected);
    }

    /** @return array<string, array{string, string}> */
    public static function unsafeLinkTargets(): array
    {
        return [
            'absolute' => ['/etc/hostname', 'outside its copy by absolute path'],
            'escaping' => ['../escaped.avi', 'does not resolve inside its copy'],
            'dangling' => ['archive/missing.avi', 'does not resolve inside its copy'],
        ];
    }

    /**
     * Two names for one inode are one file. The inventory would count them
     * twice, and losing the inode loses both.
     */
    #[Test]
    public function hard_link_aliases_are_refused(): void
    {
        [$evidence, $working, $dispositions] = $this->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);

        foreach ([$evidence, $working] as $root) {
            link($root.'/archive/recording.avi', $root.'/archive/recording-alias.avi');
        }

        $dispositions['archive/recording-alias.avi'] = [
            'disposition' => 'include_unsupported_media',
            'xattrs' => [],
        ];

        $this->assertRefusal($evidence, $working, $dispositions, 'hard links to one object');
    }

    /**
     * A claimed extended attribute this host cannot read is not a fact. It is
     * refused rather than copied into the report as though it had been observed.
     */
    #[Test]
    public function a_claimed_xattr_this_host_cannot_read_is_refused(): void
    {
        [$evidence, $working, $dispositions] = $this->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        $dispositions['.hidden-sidecar']['xattrs'] = ['user.acquired_by' => 'archive-operator'];

        $this->assertRefusal($evidence, $working, $dispositions, 'this host cannot read them');
    }

    /** And one that is readable but differs from the disk. */
    #[Test]
    public function a_claimed_xattr_that_differs_from_the_disk_is_refused(): void
    {
        [$evidence, $working, $dispositions] = $this->copies(materialiseWorkingLink: true);
        $this->app->instance(
            HistoricSourceFilesystemInspector::class,
            (new FakeHistoricSourceFilesystemInspector(supportsExtendedAttributes: true))
                ->root($evidence, 'evidence-vault')
                ->root($working, 'processing-array')
                ->xattrsFor($evidence.'/.hidden-sidecar', ['user.acquired_by' => 'somebody-else']),
        );
        $dispositions['.hidden-sidecar']['xattrs'] = ['user.acquired_by' => 'archive-operator'];

        $this->assertRefusal($evidence, $working, $dispositions, 'differs from the disk');
    }

    /**
     * A version 1 artifact was signed against a gate that never looked at the
     * disk. It stays readable and cannot satisfy the repaired one.
     */
    #[Test]
    public function version_one_custody_cannot_satisfy_the_repaired_gate(): void
    {
        [$evidence, $working, $dispositions] = $this->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);

        $this->assertRefusal(
            $evidence,
            $working,
            $dispositions,
            'unsupported or incomplete schema',
            static function (array $custody): array {
                $custody['version'] = 1;
                unset($custody['copies']['evidence']['failure_domain'], $custody['copies']['working']['failure_domain']);

                return $custody;
            },
        );
    }

    /**
     * A tree that moves while it is being read is not the tree the report would
     * describe. It is re-observed immediately before signing, and a disagreement
     * refuses rather than certifying something that no longer exists.
     */
    #[Test]
    public function a_copy_that_changes_during_verification_is_never_signed(): void
    {
        [$evidence, $working, $dispositions] = $this->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        $custody = $this->custody($evidence, $working, $dispositions);

        $this->app->instance(
            HistoricSourceFilesystemInspector::class,
            (new FakeHistoricSourceFilesystemInspector(supportsExtendedAttributes: true))
                ->root($evidence, 'evidence-vault')
                ->root($working, 'processing-array')
                ->mutateDuringFirstInventory(static function () use ($working): void {
                    file_put_contents($working.'/archive/recording.avi', 'edited-mid-verification');
                }),
        );

        $report = storage_path('app/private/source-acquisition-'.uniqid().'.json');
        $this->files[] = $report;
        config()->set('media-processing.historic_import.evidence_signing_key', 'test-signing-key');

        $this->artisan('historic-import:verify-source-acquisition', [
            'custody' => $this->jsonFile($custody),
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--report' => $report,
        ])->assertFailed();

        $this->assertFileDoesNotExist($report);
    }

    /**
     * A host that cannot expose a required fact fails closed. There is no
     * "assume protected" path.
     */
    #[Test]
    public function a_root_whose_mount_facts_cannot_be_observed_fails_closed(): void
    {
        $inspector = app(HistoricSourceFilesystemInspector::class);

        $this->assertSame(PHP_OS_FAMILY, $inspector->platform());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Historic source copy does not exist');

        $inspector->observeRoot(sys_get_temp_dir().'/historic-source-absent-'.uniqid());
    }

    /**
     * @param  array<string, array{disposition:string,xattrs:array<string,string>}>  $dispositions
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $damage
     */
    private function assertRefusal(
        string $evidence,
        string $working,
        array $dispositions,
        string $expected,
        ?callable $damage = null,
    ): void {
        $custody = $this->custody($evidence, $working, $dispositions);

        if ($damage !== null) {
            $custody = $this->sign($damage($custody));
        }

        $report = storage_path('app/private/source-acquisition-'.uniqid().'.json');
        $this->files[] = $report;
        config()->set('media-processing.historic_import.evidence_signing_key', 'test-signing-key');

        $this->artisan('historic-import:verify-source-acquisition', [
            'custody' => $this->jsonFile($custody),
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--report' => $report,
        ])
            ->expectsOutputToContain($expected)
            ->assertFailed();

        $this->assertFileDoesNotExist($report);
    }

    /**
     * @param  array<string, mixed>  $custody
     * @return array<string, mixed>
     */
    private function sign(array $custody): array
    {
        $custody['signature']['digest'] = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($custody, ['signature' => true])),
            'test-signing-key',
        );

        return $custody;
    }

    /**
     * Two real temporary trees.
     *
     * `materialiseWorkingLink` is what the `materialize_in_working_copy`
     * disposition actually asks for: the evidence copy keeps the documented
     * link, the working copy holds the bytes. The red-test fixture leaves it a
     * link in both, which is the finding.
     *
     * @return array{0:string,1:string,2:array<string, array{disposition:string,xattrs:array<string,string>}>}
     */
    private function copies(bool $materialiseWorkingLink = false): array
    {
        $base = sys_get_temp_dir().'/historic-source-'.uniqid();
        $evidence = $base.'-evidence';
        $working = $base.'-working';
        $this->roots = [$evidence, $working];

        foreach ([$evidence, $working] as $root) {
            mkdir($root.'/archive', 0755, true);
            file_put_contents($root.'/.hidden-sidecar', 'metadata');
            file_put_contents($root.'/archive/recording.avi', 'historic-video');

            if ($materialiseWorkingLink && $root === $working) {
                file_put_contents($root.'/recording-link', 'historic-video');
            } else {
                symlink('archive/recording.avi', $root.'/recording-link');
            }

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
        /**
         * A tree the verifier already refuses cannot be inventoried, and a
         * fixture for a refusal must not depend on being able to. The
         * placeholder keeps the artifact well-formed so the run is refused for
         * the reason under test rather than for a malformed custody document.
         */
        $evidenceInventory = $this->inventoryOrPlaceholder($verifier, $evidence, $dispositions, 'evidence');
        $workingInventory = $this->inventoryOrPlaceholder($verifier, $working, $dispositions, 'working');
        /**
         * Derived rather than hard-coded: materialising a link adds bytes to the
         * working copy, and a fixture that stated the total by hand would fail
         * the capacity plan for a reason that has nothing to do with the case.
         */
        $sourceBytes = array_sum(array_map(
            static fn (array $entry): int => is_int($entry['byte_size']) ? $entry['byte_size'] : 0,
            $workingInventory['entries'],
        ));
        $custody = [
            'format' => 'crockenhill-historic-source-custody',
            'version' => HistoricSourceAcquisitionVerifier::Version,
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
                'source_bytes' => $sourceBytes,
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
                    /**
                     * Version 2's addition, and the one that is compared: a
                     * declared storage identity nobody checked is how two
                     * folders on one disk passed as two copies.
                     */
                    'failure_domain' => $this->observedFailureDomain($evidence),
                    'protected_read_only' => true,
                    'inventory_hash' => $evidenceInventory['inventory_hash'],
                ],
                'working' => [
                    'storage_identity' => 'processing-array',
                    'failure_domain' => $this->observedFailureDomain($working),
                    'protected_read_only' => true,
                    'inventory_hash' => $workingInventory['inventory_hash'],
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

    /**
     * @param  array<string, array{disposition:string,xattrs:array<string,string>}>  $dispositions
     * @return array{inventory_hash:string,entries:list<array<string, mixed>>}
     */
    private function inventoryOrPlaceholder(
        HistoricSourceAcquisitionVerifier $verifier,
        string $root,
        array $dispositions,
        string $role,
    ): array {
        try {
            $inventory = $verifier->inventory($root, $dispositions, $role);

            return ['inventory_hash' => $inventory['inventory_hash'], 'entries' => $inventory['entries']];
        } catch (\RuntimeException) {
            return ['inventory_hash' => str_repeat('c', 64), 'entries' => []];
        }
    }

    /**
     * Supply the two facts this host cannot produce: a second failure domain,
     * and a copy that is genuinely not writable. Everything else stays real.
     */
    private function observeIndependentProtectedCopies(string $evidence, string $working): void
    {
        $this->app->instance(
            HistoricSourceFilesystemInspector::class,
            (new FakeHistoricSourceFilesystemInspector)
                ->root($evidence, 'evidence-vault')
                ->root($working, 'processing-array'),
        );
    }

    private function observedFailureDomain(string $root): string
    {
        return app(HistoricSourceFilesystemInspector::class)->observeRoot($root)->failureDomain();
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
