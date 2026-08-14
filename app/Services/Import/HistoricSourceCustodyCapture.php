<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Contracts\HistoricSourceFilesystemInspector;
use App\Data\HistoricSourceRootObservation;
use App\Support\CanonicalJson;
use RuntimeException;

/**
 * Produces the signed custody artifact
 * {@see HistoricSourceAcquisitionVerifier} consumes.
 *
 * HIR4 hardened the gate and left it without a producer: the artifact's
 * `inventory_hash` is a canonical hash over a per-path walk of types, modes,
 * inodes, extended attributes and NFC-normalised names, and its capacity plan
 * must equal the observed byte total exactly. Neither is authorable by hand, so
 * in practice the first command of the import had no runnable input.
 *
 * **What this class may and may not decide.** Everything observable is observed
 * here and never accepted as a claim: both inventory hashes, both failure
 * domains, write protection, the extended attributes, and the source byte
 * total. Everything that is a human act — which paths are in scope and why, the
 * physical drive's identity and health, the malware scan, the retention window
 * and the capacity acceptance — must arrive from the operator, and a missing one
 * is a refusal rather than a default.
 *
 * That split is also this producer's limit, and it is worth stating plainly:
 * because the hashes are computed with the verifier's own `inventory()`, they
 * cannot disagree with it at capture time. What the gate still independently
 * proves is *drift* — anything that changes between capture and verification,
 * on either copy, on a different host or at a later date.
 *
 * Delete alongside the acquisition verifier once the archive is imported and
 * its custody artifacts have moved to long-term custody (G9/WP10).
 */
final class HistoricSourceCustodyCapture
{
    /**
     * Capacity figures the operator plans. `source_bytes` is deliberately absent:
     * it is measured, and a hand-typed total is how a capacity plan comes to
     * describe a different corpus than the one on the disk.
     */
    private const array PlannedCapacityFields = [
        'evidence_available_bytes',
        'working_available_bytes',
        'temporary_required_bytes',
        'staging_required_bytes',
        'rollback_required_bytes',
        'approved_contingency_percent',
        'accepted',
        'planned_by',
        'planned_at',
    ];

    public function __construct(
        private readonly HistoricSourceAcquisitionVerifier $verifier,
        private readonly HistoricSourceFilesystemInspector $inspector,
        private readonly HistoricSourceDispositionWorksheet $worksheets,
    ) {}

    /**
     * @param  array<string, string>  $decisions  adjudicated disposition per path
     * @param  array<string, mixed>  $facts  operator-supplied, unobservable facts
     * @return array{custody:array<string, mixed>,unclaimable_xattrs:list<string>}
     *
     * @throws RuntimeException when any observed fact contradicts the acquisition contract
     */
    public function capture(
        array $decisions,
        array $facts,
        string $evidenceRoot,
        string $workingRoot,
        string $signingKey,
    ): array {
        if ($signingKey === '') {
            throw new RuntimeException('Historic source custody cannot be signed: no evidence signing key is configured.');
        }

        $this->validateFacts($facts);
        $roots = $this->protectedIndependentRoots($evidenceRoot, $workingRoot);
        $this->assertWorksheetDescribesBothCopies($decisions, ['evidence' => $evidenceRoot, 'working' => $workingRoot]);
        [$xattrs, $unclaimable] = $this->agreedExtendedAttributes($decisions, $roots);

        $dispositions = [];

        foreach ($decisions as $relative => $disposition) {
            $dispositions[$relative] = [
                'disposition' => $disposition,
                'xattrs' => $xattrs[$relative] ?? [],
            ];
        }

        ksort($dispositions, SORT_STRING);

        $inventories = [
            'evidence' => $this->verifier->inventory($evidenceRoot, $dispositions, 'evidence'),
            'working' => $this->verifier->inventory($workingRoot, $dispositions, 'working'),
        ];

        if (! hash_equals(
            $inventories['evidence']['logical_byte_set_hash'],
            $inventories['working']['logical_byte_set_hash'],
        )) {
            throw new RuntimeException(
                'The evidence and working copies do not hold the same content, so they are not two copies of one '
                .'source. Re-make the copy that is wrong before capturing custody.'
            );
        }

        $custody = [
            'format' => 'crockenhill-historic-source-custody',
            'version' => HistoricSourceAcquisitionVerifier::Version,
            'batch_key' => $facts['batch_key'],
            'physical_source' => $facts['physical_source'],
            'capacity_plan' => $this->capacityPlan($facts['capacity_plan'], $inventories['working']),
            'copies' => [
                'evidence' => $this->copy($facts, 'evidence', $roots, $inventories),
                'working' => $this->copy($facts, 'working', $roots, $inventories),
            ],
            'malware_scan' => $facts['malware_scan'],
            'retention' => $facts['retention'],
            'dispositions' => $dispositions,
        ];
        $custody['signature'] = [
            'algorithm' => 'hmac-sha256',
            'key_id' => $facts['key_id'],
            'digest' => hash_hmac('sha256', CanonicalJson::encode($custody), $signingKey),
        ];

        return ['custody' => $custody, 'unclaimable_xattrs' => $unclaimable];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, HistoricSourceRootObservation>  $roots
     * @param  array<string, array<string, mixed>>  $inventories
     * @return array<string, mixed>
     */
    private function copy(array $facts, string $role, array $roots, array $inventories): array
    {
        return [
            'storage_identity' => $facts['storage_identity'][$role],
            'failure_domain' => $roots[$role]->failureDomain(),
            'protected_read_only' => true,
            'inventory_hash' => $inventories[$role]['inventory_hash'],
        ];
    }

    /**
     * The gate refuses a mismatched path set as "unobserved paths" without
     * saying which, and it does so only after hashing every file in both
     * copies. Diffing first names the paths and the direction, and on a real
     * drive it does so before the expensive pass rather than after it.
     *
     * @param  array<string, string>  $decisions
     * @param  array<string, string>  $copies
     */
    private function assertWorksheetDescribesBothCopies(array $decisions, array $copies): void
    {
        $adjudicated = array_keys($decisions);

        foreach ($copies as $role => $root) {
            $observed = array_keys($this->worksheets->draft($root)['paths']);
            $missing = array_diff($adjudicated, $observed);
            $extra = array_diff($observed, $adjudicated);

            if ($missing !== []) {
                throw new RuntimeException(
                    "The worksheet adjudicates paths the {$role} copy does not hold: ".implode(', ', $missing)
                    .'. Re-draft it against the copy as it stands now.'
                );
            }

            if ($extra !== []) {
                throw new RuntimeException(
                    "The {$role} copy holds paths the worksheet never adjudicated: ".implode(', ', $extra)
                    .'. Re-draft it against the copy as it stands now.'
                );
            }
        }
    }

    /**
     * @return array<string, HistoricSourceRootObservation>
     */
    private function protectedIndependentRoots(string $evidenceRoot, string $workingRoot): array
    {
        $roots = [
            'evidence' => $this->inspector->observeRoot($evidenceRoot),
            'working' => $this->inspector->observeRoot($workingRoot),
        ];

        foreach ($roots as $role => $observation) {
            /**
             * The gate accepts `protected_read_only: true` only where a write
             * probe actually failed, so capturing an artifact for a writable
             * copy would produce a document that cannot pass. Refuse while the
             * remedy is still "protect the copy" rather than "re-acquire".
             */
            if (! $observation->writeProbeFailed) {
                throw new RuntimeException(
                    "The {$role} source copy is still writable: a write probe succeeded against it and the mount "
                    .'reports '.($observation->readOnly ? 'ro' : 'rw').'. Protect the copy before capturing custody.'
                );
            }
        }

        if ($roots['evidence']->failureDomain() === $roots['working']->failureDomain()) {
            throw new RuntimeException(
                'The evidence and working copies share one failure domain: they are on the same mounted device, so a '
                .'single filesystem loss defeats both. Move one copy before capturing custody.'
            );
        }

        return $roots;
    }

    /**
     * Only attributes present on *both* copies with the same value can be
     * claimed: the gate compares the claim against each tree in turn, so a
     * one-sided attribute would make the artifact unverifiable. The dropped ones
     * are returned rather than swallowed, because an attribute that exists on
     * one copy and not the other usually means a copy was made with the wrong
     * tool.
     *
     * @param  array<string, string>  $decisions
     * @param  array<string, HistoricSourceRootObservation>  $roots
     * @return array{0:array<string, array<string, string>>,1:list<string>}
     */
    private function agreedExtendedAttributes(array $decisions, array $roots): array
    {
        if (! $this->inspector->supportsExtendedAttributes()) {
            return [[], []];
        }

        $claims = [];
        $unclaimable = [];

        foreach (array_keys($decisions) as $relative) {
            $observed = [];

            foreach ($roots as $role => $observation) {
                $observed[$role] = $this->inspector->xattrs($observation->canonicalPath.'/'.$relative);
            }

            $agreed = [];

            foreach ($observed['evidence'] as $name => $value) {
                if (($observed['working'][$name] ?? null) === $value) {
                    $agreed[$name] = $value;

                    continue;
                }

                $unclaimable[] = "{$relative}: {$name} (evidence only, or differing values)";
            }

            foreach (array_keys($observed['working']) as $name) {
                if (! array_key_exists($name, $observed['evidence'])) {
                    $unclaimable[] = "{$relative}: {$name} (working copy only)";
                }
            }

            if ($agreed !== []) {
                ksort($agreed, SORT_STRING);
                $claims[$relative] = $agreed;
            }
        }

        return [$claims, $unclaimable];
    }

    /**
     * @param  array<string, mixed>  $planned
     * @param  array<string, mixed>  $workingInventory
     * @return array<string, mixed>
     */
    private function capacityPlan(array $planned, array $workingInventory): array
    {
        $observedBytes = array_sum(array_map(
            static fn (array $entry): int => is_int($entry['byte_size']) ? $entry['byte_size'] : 0,
            $workingInventory['entries'],
        ));

        if ($planned['accepted'] !== true) {
            throw new RuntimeException(
                'The capacity plan is not accepted. Acquisition cannot proceed on an unaccepted plan.'
            );
        }

        $plan = ['source_bytes' => $observedBytes] + $planned;
        $requirement = $plan['source_bytes']
            + $plan['temporary_required_bytes']
            + $plan['staging_required_bytes']
            + $plan['rollback_required_bytes'];
        $requirement += (int) ceil($requirement * ($plan['approved_contingency_percent'] / 100));

        if ($plan['evidence_available_bytes'] < $observedBytes) {
            throw new RuntimeException(
                "The evidence copy's capacity of {$plan['evidence_available_bytes']} bytes cannot hold the observed "
                ."{$observedBytes} bytes of source."
            );
        }

        if ($plan['working_available_bytes'] < $requirement) {
            throw new RuntimeException(
                "The working copy's capacity of {$plan['working_available_bytes']} bytes cannot cover the required "
                ."{$requirement} bytes (source, temporary, staging, rollback plus "
                ."{$plan['approved_contingency_percent']}% contingency)."
            );
        }

        return $plan;
    }

    /** @param array<string, mixed> $facts */
    private function validateFacts(array $facts): void
    {
        foreach (['batch_key', 'key_id'] as $field) {
            if (! is_string($facts[$field] ?? null) || trim((string) $facts[$field]) === '') {
                throw new RuntimeException("Historic source acquisition facts are missing {$field}.");
            }
        }

        foreach (['physical_source', 'malware_scan', 'retention', 'storage_identity', 'capacity_plan'] as $block) {
            if (! is_array($facts[$block] ?? null)) {
                throw new RuntimeException("Historic source acquisition facts are missing the {$block} block.");
            }
        }

        foreach (['evidence', 'working'] as $role) {
            if (! is_string($facts['storage_identity'][$role] ?? null)
                || trim((string) $facts['storage_identity'][$role]) === '') {
                throw new RuntimeException("Historic source acquisition facts are missing the {$role} storage identity.");
            }
        }

        if ($facts['storage_identity']['evidence'] === $facts['storage_identity']['working']) {
            throw new RuntimeException('The two source copies cannot share one storage identity.');
        }

        $capacity = $facts['capacity_plan'];

        if (array_key_exists('source_bytes', $capacity)) {
            throw new RuntimeException(
                'The capacity plan must not declare source_bytes: the source total is measured from the working copy, '
                .'not stated. Remove it and let acquisition observe it.'
            );
        }

        $missing = array_diff(self::PlannedCapacityFields, array_keys($capacity));

        if ($missing !== []) {
            throw new RuntimeException('The capacity plan is missing '.implode(', ', $missing).'.');
        }

        $unknown = array_diff(array_keys($capacity), self::PlannedCapacityFields);

        if ($unknown !== []) {
            throw new RuntimeException('The capacity plan has unknown fields: '.implode(', ', $unknown).'.');
        }
    }
}
