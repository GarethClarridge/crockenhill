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
 * HIR4 left the acquisition gate with a verifier and no producer.
 *
 * {@see HistoricSourceAcquisitionVerifier} demands a signed custody artifact
 * whose `inventory_hash` is `CanonicalJson::hash()` over a per-path walk of
 * types, modes, inodes, xattrs and NFC-normalised names, and whose capacity plan
 * must equal the observed byte total exactly. Nothing in the application emitted
 * one, so the first command of the import — the one run with the drive mounted
 * read-only, where a retry means re-mounting the original — had no executable
 * input.
 *
 * The acceptance criterion is therefore not "the artifact looks right". It is
 * that the gate itself accepts what this command produces, which is what
 * {@see self::it_captures_a_custody_artifact_the_acquisition_gate_accepts()}
 * asserts by running both commands in sequence.
 */
class CaptureHistoricSourceAcquisitionCommandTest extends TestCase
{
    private HistoricSourceCopyFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(storage_path('app/private'));

        $this->fixture = new HistoricSourceCopyFixture;
        config()->set('media-processing.historic_import.evidence_signing_key', 'test-signing-key');
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanUp();

        parent::tearDown();
    }

    #[Test]
    public function it_captures_a_custody_artifact_the_acquisition_gate_accepts(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        $custody = $this->fixture->reservedPath('custody');

        $this->artisan('historic-import:capture-source-acquisition', [
            'worksheet' => $this->completedWorksheet($evidence),
            'facts' => $this->facts(),
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--custody' => $custody,
        ])->assertSuccessful();

        $report = $this->fixture->reservedPath('report');

        $this->artisan('historic-import:verify-source-acquisition', [
            'custody' => $custody,
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--report' => $report,
        ])->assertSuccessful();

        $verified = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(4, $verified['copies']['working']['path_count']);
        $this->assertSame('historic-source-test', $verified['batch_key']);
        $this->assertNotSame(
            $verified['copies']['evidence']['inventory_hash'],
            $verified['copies']['working']['inventory_hash'],
        );
    }

    /**
     * Non-vacuity for the case above, and the honest limit of this producer.
     *
     * Capture computes its hashes with the verifier's own `inventory()`, so the
     * two cannot disagree at capture time — a passing end-to-end run proves the
     * schema is right, not that the gate is discriminating. What the gate does
     * still prove independently is drift: a copy that changes after signing.
     * Without this case, a capture that emitted constant hashes would satisfy
     * every other test here.
     */
    #[Test]
    public function it_produces_custody_the_gate_rejects_once_a_copy_drifts(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        $custody = $this->fixture->reservedPath('custody');

        $this->artisan('historic-import:capture-source-acquisition', [
            'worksheet' => $this->completedWorksheet($evidence),
            'facts' => $this->facts(),
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--custody' => $custody,
        ])->assertSuccessful();

        file_put_contents($working.'/archive/recording.avi', 'tampered-video');

        $this->artisan('historic-import:verify-source-acquisition', [
            'custody' => $custody,
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--report' => $this->fixture->reservedPath('report'),
        ])
            ->expectsOutputToContain('does not match its acquired checksum')
            ->assertFailed();
    }

    /**
     * The gate refuses an undispositioned path with "no exact disposition/xattr
     * record". Refusing here instead names the path while the operator is still
     * adjudicating, rather than after the artifact has been signed.
     */
    #[Test]
    public function it_refuses_a_worksheet_with_an_undecided_path(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        $worksheet = $this->completedWorksheet($evidence, function (array $drafted): array {
            $drafted['paths']['archive/recording.avi']['disposition'] = null;

            return $drafted;
        });

        $this->assertRefusal($worksheet, $this->facts(), $evidence, $working, 'archive/recording.avi');
    }

    /**
     * D5: what carries the weight is the written reason on every include and
     * exclude. The custody schema has no per-path room for one — `exactKeys()`
     * allows only `disposition` and `xattrs` — so the reason is recorded once
     * per disposition class in the worksheet, and every class in use must have
     * one.
     */
    #[Test]
    public function it_refuses_a_disposition_with_no_written_reason(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        $worksheet = $this->completedWorksheet($evidence, function (array $drafted): array {
            unset($drafted['disposition_reasons']['include_unsupported_media']);

            return $drafted;
        });

        $this->assertRefusal($worksheet, $this->facts(), $evidence, $working, 'include_unsupported_media');
    }

    /**
     * A worksheet drafted before the copy changed describes a tree that no
     * longer exists. The gate would catch it as "unobserved paths"; catching it
     * here says which path and which direction.
     */
    #[Test]
    public function it_refuses_a_worksheet_whose_paths_no_longer_match_the_copy(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        $worksheet = $this->completedWorksheet($evidence);
        unlink($evidence.'/.hidden-sidecar');
        unlink($working.'/.hidden-sidecar');

        $this->assertRefusal($worksheet, $this->facts(), $evidence, $working, '.hidden-sidecar');
    }

    /**
     * The byte total is the one capacity figure that is an observation, not a
     * plan. A hand-typed one is how a capacity plan comes to describe a
     * different corpus than the one on the disk.
     */
    #[Test]
    public function it_derives_the_capacity_total_and_refuses_a_hand_typed_one(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        $facts = $this->facts(function (array $facts): array {
            $facts['capacity_plan']['source_bytes'] = 999_999;

            return $facts;
        });

        $this->assertRefusal($this->completedWorksheet($evidence), $facts, $evidence, $working, 'source_bytes');
    }

    #[Test]
    public function it_records_the_observed_byte_total_rather_than_any_declared_one(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        $custody = $this->fixture->reservedPath('custody');

        $this->artisan('historic-import:capture-source-acquisition', [
            'worksheet' => $this->completedWorksheet($evidence),
            'facts' => $this->facts(),
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--custody' => $custody,
        ])->assertSuccessful();

        $captured = json_decode((string) file_get_contents($custody), true, flags: JSON_THROW_ON_ERROR);
        $observed = strlen('metadata') + strlen('historic-video') + strlen('historic-video');

        $this->assertSame($observed, $captured['capacity_plan']['source_bytes']);
    }

    /**
     * The gate accepts `protected_read_only: true` only when a write probe
     * actually failed, so emitting it for a writable copy would produce an
     * artifact that cannot pass. Refuse while the fix is still "protect the
     * copy".
     */
    #[Test]
    public function it_refuses_a_copy_a_write_probe_can_still_write_to(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->app->instance(
            HistoricSourceFilesystemInspector::class,
            (new FakeHistoricSourceFilesystemInspector)
                ->root($evidence, 'evidence-vault')
                ->root($working, 'processing-array', readOnly: false, writeProbeFailed: false),
        );

        $this->assertRefusal(
            $this->completedWorksheet($evidence),
            $this->facts(),
            $evidence,
            $working,
            'write probe',
        );
    }

    #[Test]
    public function it_refuses_two_copies_that_share_one_failure_domain(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->app->instance(
            HistoricSourceFilesystemInspector::class,
            (new FakeHistoricSourceFilesystemInspector)
                ->root($evidence, 'one-array')
                ->root($working, 'one-array'),
        );

        $this->assertRefusal(
            $this->completedWorksheet($evidence),
            $this->facts(),
            $evidence,
            $working,
            'failure domain',
        );
    }

    /**
     * The working copy must hold the source, plus temp, staging and rollback,
     * plus the approved contingency. Discovering the shortfall at the gate would
     * mean discovering it with the drive already connected.
     */
    #[Test]
    public function it_refuses_a_capacity_plan_that_cannot_cover_the_working_requirement(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        $facts = $this->facts(function (array $facts): array {
            $facts['capacity_plan']['working_available_bytes'] = 10;

            return $facts;
        });

        $this->assertRefusal($this->completedWorksheet($evidence), $facts, $evidence, $working, 'capacity');
    }

    #[Test]
    public function it_refuses_a_capacity_plan_nobody_accepted(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        $facts = $this->facts(function (array $facts): array {
            $facts['capacity_plan']['accepted'] = false;

            return $facts;
        });

        $this->assertRefusal($this->completedWorksheet($evidence), $facts, $evidence, $working, 'accepted');
    }

    /**
     * A custody artifact that can be silently replaced is not custody.
     */
    #[Test]
    public function it_refuses_to_overwrite_an_existing_custody_artifact(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        $custody = $this->fixture->reservedPath('custody');
        file_put_contents($custody, '{"already":"captured"}');

        $this->artisan('historic-import:capture-source-acquisition', [
            'worksheet' => $this->completedWorksheet($evidence),
            'facts' => $this->facts(),
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--custody' => $custody,
        ])->assertFailed();

        $this->assertSame('{"already":"captured"}', file_get_contents($custody));
    }

    /**
     * An extended attribute on one copy and not the other cannot be claimed:
     * the gate compares the claim against *both* trees, so claiming it would
     * make the artifact unverifiable. Capture claims the agreeing intersection
     * and says which attributes it dropped, rather than emitting a document
     * that fails later for a reason the operator never saw.
     */
    #[Test]
    public function it_claims_only_extended_attributes_present_on_both_copies(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->app->instance(
            HistoricSourceFilesystemInspector::class,
            (new FakeHistoricSourceFilesystemInspector(supportsExtendedAttributes: true))
                ->root($evidence, 'evidence-vault')
                ->root($working, 'processing-array')
                ->xattrsFor($evidence.'/archive/recording.avi', [
                    'user.origin' => 'cbc-drive',
                    'user.evidence-only' => 'yes',
                ])
                ->xattrsFor($working.'/archive/recording.avi', ['user.origin' => 'cbc-drive']),
        );
        $custody = $this->fixture->reservedPath('custody');

        $this->artisan('historic-import:capture-source-acquisition', [
            'worksheet' => $this->completedWorksheet($evidence),
            'facts' => $this->facts(),
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--custody' => $custody,
        ])
            ->expectsOutputToContain('user.evidence-only')
            ->assertSuccessful();

        $captured = json_decode((string) file_get_contents($custody), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['user.origin' => 'cbc-drive'],
            $captured['dispositions']['archive/recording.avi']['xattrs'],
        );

        $this->artisan('historic-import:verify-source-acquisition', [
            'custody' => $custody,
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--report' => $this->fixture->reservedPath('report'),
        ])->assertSuccessful();
    }

    #[Test]
    public function it_refuses_to_sign_without_a_configured_evidence_key(): void
    {
        [$evidence, $working] = $this->fixture->copies(materialiseWorkingLink: true);
        $this->observeIndependentProtectedCopies($evidence, $working);
        config()->set('media-processing.historic_import.evidence_signing_key', null);

        $this->assertRefusal(
            $this->completedWorksheet($evidence),
            $this->facts(),
            $evidence,
            $working,
            'signing key',
        );
    }

    private function assertRefusal(
        string $worksheet,
        string $facts,
        string $evidence,
        string $working,
        string $expected,
    ): void {
        $custody = $this->fixture->reservedPath('custody');

        $this->artisan('historic-import:capture-source-acquisition', [
            'worksheet' => $worksheet,
            'facts' => $facts,
            'evidence-copy' => $evidence,
            'working-copy' => $working,
            '--custody' => $custody,
        ])
            ->expectsOutputToContain($expected)
            ->assertFailed();

        $this->assertFileDoesNotExist($custody);
    }

    /**
     * Draft the worksheet with the real command, then adjudicate it the way an
     * operator would. Deriving it from the draft rather than hand-writing one
     * keeps these cases honest about the path set.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $revise
     */
    private function completedWorksheet(string $copy, ?callable $revise = null): string
    {
        $path = $this->fixture->reservedPath('worksheet');

        $this->artisan('historic-import:draft-source-dispositions', [
            'copy' => $copy,
            '--worksheet' => $path,
        ])->assertSuccessful();

        $drafted = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $decisions = [
            '.hidden-sidecar' => 'preserve_sidecar',
            'archive' => 'traverse',
            'archive/recording.avi' => 'include_unsupported_media',
            'recording-link' => 'materialize_in_working_copy',
        ];

        foreach ($decisions as $relative => $disposition) {
            $drafted['paths'][$relative]['disposition'] = $disposition;
        }

        $drafted['disposition_reasons'] = [
            'preserve_sidecar' => 'Sidecar metadata retained with its recording.',
            'traverse' => 'Directory carries no content of its own.',
            'include_unsupported_media' => 'Legacy AVI is in scope for the historic import.',
            'materialize_in_working_copy' => 'Link is documented; the working copy must hold the bytes.',
        ];

        if ($revise !== null) {
            $drafted = $revise($drafted);
        }

        unlink($path);
        file_put_contents($path, json_encode($drafted, JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $revise
     */
    private function facts(?callable $revise = null): string
    {
        $facts = [
            'batch_key' => 'historic-source-test',
            'key_id' => 'acquisition-key-1',
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
            'storage_identity' => [
                'evidence' => 'offline-evidence-vault',
                'working' => 'processing-array',
            ],
            'capacity_plan' => [
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
        ];

        return $this->fixture->jsonFile('facts', $revise === null ? $facts : $revise($facts));
    }

    private function observeIndependentProtectedCopies(string $evidence, string $working): void
    {
        $this->app->instance(
            HistoricSourceFilesystemInspector::class,
            (new FakeHistoricSourceFilesystemInspector)
                ->root($evidence, 'evidence-vault')
                ->root($working, 'processing-array'),
        );
    }
}
