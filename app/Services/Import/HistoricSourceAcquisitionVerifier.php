<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Contracts\HistoricSourceFilesystemInspector;
use App\Data\HistoricSourceRootObservation;
use App\Support\CanonicalJson;
use FilesystemIterator;
use Normalizer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * HIR4: source custody proved from observed storage facts, not from signed
 * claims alone.
 *
 * Version 1 accepted two writable sibling folders on one disk as "two signed
 * complete copies". Its only independence check was that `realpath()` differed;
 * `storage_identity` and `protected_read_only` were claims nobody compared with
 * the disk, and a `materialize_in_working_copy` disposition could stay a symlink.
 * A single filesystem loss defeated both copies while the gate reported success.
 *
 * Version 2 keeps every signed field as *expected authority* and compares each
 * one with what {@see HistoricSourceFilesystemInspector} observes. A claim that
 * cannot be observed fails; it is never copied into the report as though it had
 * been.
 *
 * Delete once the archive is imported and its custody artifacts have moved to
 * long-term custody (G9/WP10).
 */
final class HistoricSourceAcquisitionVerifier
{
    /**
     * Version 2 (HIR4). Version 1 artifacts remain retained and readable but
     * cannot satisfy G5/G7: they were signed against a gate that never looked
     * at the disk.
     */
    public const int Version = 2;

    public function __construct(
        private readonly HistoricSourceFilesystemInspector $inspector,
    ) {}

    /**
     * @param  array<string, mixed>  $custody
     * @return array<string, mixed>
     */
    public function verify(array $custody, string $evidenceRoot, string $workingRoot, string $signingKey): array
    {
        $this->validateCustody($custody, $signingKey);
        $dispositions = $custody['dispositions'] ?? null;
        $copies = $custody['copies'] ?? null;

        if (! is_array($dispositions) || ! is_array($copies)
            || ! is_array($copies['evidence'] ?? null)
            || ! is_array($copies['working'] ?? null)) {
            throw new RuntimeException('Historic source custody copy/disposition bindings are invalid.');
        }

        $observations = [
            'evidence' => $this->inspector->observeRoot($evidenceRoot),
            'working' => $this->inspector->observeRoot($workingRoot),
        ];

        $this->assertIndependentProtectedCopies($copies, $observations);

        $evidence = $this->inventory($evidenceRoot, $dispositions, 'evidence');
        $working = $this->inventory($workingRoot, $dispositions, 'working');

        foreach (['evidence' => $evidence, 'working' => $working] as $role => $inventory) {
            $expected = $copies[$role]['inventory_hash'] ?? null;

            if (! is_string($expected) || ! hash_equals($expected, $inventory['inventory_hash'])) {
                throw new RuntimeException("Observed {$role} copy inventory does not match its acquired checksum.");
            }
        }

        /**
         * The logical byte set, not the physical inventory. An approved evidence
         * symlink and its materialised working file are the same content and
         * deliberately different objects, so comparing physical inventories here
         * would refuse the very disposition the custody artifact asked for.
         */
        if (! hash_equals($evidence['logical_byte_set_hash'], $working['logical_byte_set_hash'])) {
            throw new RuntimeException('Evidence and working copies do not contain the same complete path/byte set.');
        }

        $this->validateCapacityPlan($custody['capacity_plan'], $working);

        $report = [
            'format' => 'crockenhill-historic-source-acquisition',
            'version' => self::Version,
            'batch_key' => $custody['batch_key'],
            'inspector' => [
                'platform' => $this->inspector->platform(),
                'evidence' => $observations['evidence']->toArray(),
                'working' => $observations['working']->toArray(),
            ],
            'custody_hash' => CanonicalJson::hash(array_diff_key($custody, ['signature' => true])),
            'physical_source' => $custody['physical_source'],
            'capacity_plan' => $custody['capacity_plan'],
            'copies' => [
                'evidence' => $copies['evidence'] + $evidence,
                'working' => $copies['working'] + $working,
            ],
            /**
             * Re-observed immediately before the report is signed. The inventory
             * above was read first; if anything moved in between, the two
             * disagree and the acquisition is refused rather than certified
             * against a tree that no longer exists.
             */
            'reobserved' => $this->reobserve($evidenceRoot, $workingRoot, $dispositions, $evidence, $working),
            'malware_scan' => $custody['malware_scan'],
            'retention' => $custody['retention'],
            'verified_at' => now()->utc()->toIso8601String(),
        ];
        $report['signature'] = [
            'algorithm' => 'hmac-sha256',
            'key_id' => $custody['signature']['key_id'],
            'digest' => hash_hmac('sha256', CanonicalJson::encode($report), $signingKey),
        ];

        return $report;
    }

    /**
     * @param  array<string, mixed>  $dispositions
     * @return array{inventory_hash:string,content_hash:string,logical_byte_set_hash:string,path_count:int,entries:list<array<string, mixed>>}
     */
    public function inventory(string $root, array $dispositions, ?string $role = null): array
    {
        $resolvedRoot = realpath($root);

        if (! is_string($resolvedRoot) || ! is_dir($resolvedRoot)) {
            throw new RuntimeException("Historic source copy does not exist: {$root}");
        }

        $entries = [];
        $collisions = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $path = $item->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($resolvedRoot) + 1));
            $authority = $dispositions[$relative] ?? null;

            if (! is_array($authority)
                || ! is_string($authority['disposition'] ?? null)
                || trim($authority['disposition']) === ''
                || ! is_array($authority['xattrs'] ?? null)) {
                throw new RuntimeException("Historic source path has no exact disposition/xattr record: {$relative}");
            }

            /** @var array{disposition:string,xattrs:array<string, string>} $authority */
            $collisionKey = mb_strtolower(
                class_exists(Normalizer::class)
                    ? (Normalizer::normalize($relative, Normalizer::FORM_C) ?: $relative)
                    : $relative,
            );

            if (isset($collisions[$collisionKey]) && $collisions[$collisionKey] !== $relative) {
                throw new RuntimeException("Historic source contains a case or Unicode-normalisation collision: {$relative}");
            }

            $collisions[$collisionKey] = $relative;
            $entries[] = $this->entry($item, $relative, $authority, $resolvedRoot, $role);
        }

        ksort($dispositions, SORT_STRING);
        $observed = array_column($entries, 'relative_path');
        sort($observed, SORT_STRING);

        if ($observed !== array_keys($dispositions)) {
            throw new RuntimeException('Historic source disposition artifact contains unobserved paths.');
        }

        usort($entries, static fn (array $left, array $right): int => $left['relative_path'] <=> $right['relative_path']);
        $readErrors = array_filter($entries, static fn (array $entry): bool => $entry['read_error'] !== null);

        if ($readErrors !== []) {
            throw new RuntimeException('Historic source inventory encountered one or more read errors.');
        }

        $this->assertNoHardLinkAliases($entries);

        return [
            /**
             * The physical inventory: actual types, links, xattrs and modes,
             * which differ between an evidence symlink and its materialised
             * working file and are supposed to.
             */
            'inventory_hash' => CanonicalJson::hash($entries),
            'content_hash' => CanonicalJson::hash(array_map(static fn (array $entry): array => [
                'relative_path' => $entry['relative_path'],
                'type' => $entry['type'],
                'link_target' => $entry['link_target'],
                'byte_size' => $entry['byte_size'],
                'sha256' => $entry['sha256'],
            ], $entries)),
            /**
             * The logical byte set: what each path *contains*, with a symlink
             * resolved to the bytes it points at. This is what makes an approved
             * evidence link and a materialised working file provably the same
             * content without pretending their physical inventories are equal.
             */
            'logical_byte_set_hash' => CanonicalJson::hash(array_map(static fn (array $entry): array => [
                'relative_path' => $entry['relative_path'],
                'logical_type' => $entry['logical_type'],
                'byte_size' => $entry['logical_byte_size'],
                'sha256' => $entry['logical_sha256'],
            ], $entries)),
            'path_count' => count($entries),
            'entries' => $entries,
        ];
    }

    /**
     * @param  array{disposition:string,xattrs:array<string, string>}  $authority
     * @return array<string, mixed>
     */
    private function entry(
        SplFileInfo $item,
        string $relative,
        array $authority,
        string $resolvedRoot,
        ?string $role,
    ): array {
        $path = $item->getPathname();
        $type = $item->isLink() ? 'symlink' : ($item->isDir() ? 'directory' : ($item->isFile() ? 'file' : 'other'));
        $stat = lstat($path);
        $size = null;
        $hash = null;
        $readError = null;
        $linkTarget = null;
        $disposition = trim($authority['disposition']);

        if ($type === 'symlink') {
            $observedTarget = readlink($path);

            if (is_string($observedTarget)) {
                $linkTarget = $observedTarget;
                $this->assertContainedLink($relative, $observedTarget, $path, $resolvedRoot);
            } else {
                $readError = 'readlink_failed';
            }
        }

        if ($type === 'file') {
            try {
                $observedSize = $item->getSize();
                $observedHash = hash_file('sha256', $path);
                $size = is_int($observedSize) ? $observedSize : null;
                $hash = is_string($observedHash) ? $observedHash : null;

                if ($hash === null) {
                    $readError = 'hash_failed';
                }
            } catch (Throwable) {
                $readError = 'read_failed';
            }
        }

        $this->assertDispositionMaterialised($relative, $disposition, $type, $role);
        /**
         * Observed, not copied from the claim. A signed xattr that differs from
         * the disk, or one that cannot be read at all, is the whole point of the
         * comparison.
         */
        $observedXattrs = $this->observedXattrs($relative, $path, $authority['xattrs']);
        $this->assertClaimedXattrs($relative, $authority['xattrs'], $observedXattrs);

        [$logicalType, $logicalSize, $logicalHash] = $this->logicalContent($path, $type, $size, $hash);

        return [
            'relative_path' => $relative,
            'raw_path_base64' => base64_encode($relative),
            'type' => $type,
            'link_target' => $linkTarget,
            'byte_size' => $size,
            'sha256' => $hash,
            'logical_type' => $logicalType,
            'logical_byte_size' => $logicalSize,
            'logical_sha256' => $logicalHash,
            'modified_at' => $item->getMTime(),
            'mode' => substr(sprintf('%o', $item->getPerms()), -4),
            'device' => is_array($stat) ? $stat['dev'] : null,
            'inode' => is_array($stat) ? $stat['ino'] : null,
            'hard_link_count' => is_array($stat) ? $stat['nlink'] : null,
            'xattrs' => $observedXattrs,
            'disposition' => $disposition,
            'read_error' => $readError,
        ];
    }

    /**
     * What this path *contains*, with an approved symlink resolved through to
     * the bytes at its target.
     *
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    private function logicalContent(string $path, string $type, ?int $size, ?string $hash): array
    {
        if ($type !== 'symlink') {
            return [$type === 'directory' ? 'directory' : $type, $size, $hash];
        }

        $resolved = realpath($path);

        if (! is_string($resolved) || ! is_file($resolved)) {
            /**
             * A link to a directory, or to nothing this copy holds. There are no
             * bytes to compare, and `assertContainedLink()` has already refused
             * anything that escapes the copy.
             */
            return ['symlink', null, null];
        }

        $resolvedHash = hash_file('sha256', $resolved);

        $resolvedSize = filesize($resolved);

        return ['file', is_int($resolvedSize) ? $resolvedSize : null, is_string($resolvedHash) ? $resolvedHash : null];
    }

    /**
     * A link may point only at something inside its own copy.
     *
     * Absolute, escaping, cyclic and externally targeted links are all the same
     * failure: the copy is no longer self-contained, so restoring it restores
     * something that depends on a host it may never see again.
     */
    private function assertContainedLink(string $relative, string $target, string $path, string $resolvedRoot): void
    {
        if (str_starts_with($target, '/')) {
            throw new RuntimeException("Historic source link {$relative} points outside its copy by absolute path.");
        }

        $resolved = realpath($path);

        if (! is_string($resolved)) {
            throw new RuntimeException("Historic source link {$relative} does not resolve inside its copy.");
        }

        if ($resolved !== $resolvedRoot && ! str_starts_with($resolved, $resolvedRoot.'/')) {
            throw new RuntimeException("Historic source link {$relative} points outside its copy.");
        }
    }

    /**
     * The disposition has to have happened, not merely been declared.
     *
     * `materialize_in_working_copy` staying a symlink in the working copy was
     * the review's example: the artifact said the bytes were there and they were
     * not.
     */
    private function assertDispositionMaterialised(
        string $relative,
        string $disposition,
        string $type,
        ?string $role,
    ): void {
        if ($disposition === 'materialize_in_working_copy' && $role === 'working' && $type !== 'file') {
            throw new RuntimeException(
                "Historic source path {$relative} is dispositioned materialize_in_working_copy but is still a {$type} "
                .'in the working copy.'
            );
        }

        if ($disposition === 'traverse' && $type !== 'directory') {
            throw new RuntimeException("Historic source path {$relative} is dispositioned traverse but is a {$type}.");
        }
    }

    /**
     * A host that cannot read extended attributes may still verify a copy whose
     * custody claims none — there is nothing to check. It may not verify one
     * that claims an attribute, because the claim would go into the report
     * unexamined, which is the substitution HIR4 exists to stop.
     *
     * @param  array<string, string>  $claimed
     * @return array<string, string>
     */
    private function observedXattrs(string $relative, string $path, array $claimed): array
    {
        if (! $this->inspector->supportsExtendedAttributes()) {
            if ($claimed !== []) {
                throw new RuntimeException(
                    "Historic source custody claims extended attributes on {$relative}, but this host cannot read "
                    .'them. Verify the acquisition on a host that can, or revise the custody artifact.'
                );
            }

            return [];
        }

        return $this->inspector->xattrs($path);
    }

    /**
     * @param  array<string, string>  $claimed
     * @param  array<string, string>  $observed
     */
    private function assertClaimedXattrs(string $relative, array $claimed, array $observed): void
    {
        foreach ($claimed as $name => $value) {
            if (! array_key_exists($name, $observed)) {
                throw new RuntimeException(
                    "Historic source custody claims extended attribute {$name} on {$relative}, which is not present.",
                );
            }

            if (! hash_equals($observed[$name], $value)) {
                throw new RuntimeException(
                    "Historic source custody extended attribute {$name} on {$relative} differs from the disk.",
                );
            }
        }
    }

    /**
     * Two paths sharing one inode are one object wearing two names.
     *
     * The inventory would count them twice and the byte set would agree, but a
     * copy that "contains" a file twice contains it once — and losing that one
     * inode loses both.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function assertNoHardLinkAliases(array $entries): void
    {
        $seen = [];

        foreach ($entries as $entry) {
            if ($entry['type'] !== 'file' || ! is_int($entry['hard_link_count']) || $entry['hard_link_count'] < 2) {
                continue;
            }

            $identity = $entry['device'].':'.$entry['inode'];

            if (isset($seen[$identity])) {
                throw new RuntimeException(
                    "Historic source paths {$seen[$identity]} and {$entry['relative_path']} are hard links to one "
                    .'object, so the copy holds fewer distinct files than its inventory claims.'
                );
            }

            $seen[$identity] = $entry['relative_path'];
        }
    }

    /**
     * Two copies must differ in failure domain, not merely in path or in the
     * `storage_identity` string somebody typed into the custody artifact.
     *
     * @param  array<string, mixed>  $copies
     * @param  array<string, HistoricSourceRootObservation>  $observations
     */
    private function assertIndependentProtectedCopies(array $copies, array $observations): void
    {
        if ($observations['evidence']->canonicalPath === $observations['working']->canonicalPath) {
            throw new RuntimeException('Evidence and working copies must be independent roots.');
        }

        if ($observations['evidence']->failureDomain() === $observations['working']->failureDomain()) {
            throw new RuntimeException(
                'Evidence and working copies share one failure domain: they are on the same mounted device, so a '
                .'single filesystem loss defeats both. Two independently protected copies are required.'
            );
        }

        foreach ($observations as $role => $observation) {
            $claimed = $copies[$role] ?? null;

            if (! is_array($claimed)) {
                throw new RuntimeException("{$role} source copy evidence is invalid.");
            }

            /**
             * The write probe decides, not the mount option. A read-only mount
             * is the strongest form of protection but not the only valid one,
             * and an option string can say `ro` over a filesystem exported
             * writable underneath. What the gate needs to know is whether this
             * process can actually write into the copy.
             */
            if (($claimed['protected_read_only'] ?? null) === true && ! $observation->writeProbeFailed) {
                throw new RuntimeException(
                    "The {$role} source copy is claimed protected read-only, but a write probe succeeded against it. "
                    .'The mount reports '.($observation->readOnly ? 'ro' : 'rw').'.'
                );
            }

            if (isset($claimed['failure_domain']) && $claimed['failure_domain'] !== $observation->failureDomain()) {
                throw new RuntimeException(
                    "The {$role} source copy's recorded failure domain does not match the observed one.",
                );
            }
        }
    }

    /**
     * Read the trees again and prove nothing moved while they were being
     * inventoried.
     *
     * @param  array<string, mixed>  $dispositions
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $working
     * @return array<string, string>
     */
    private function reobserve(
        string $evidenceRoot,
        string $workingRoot,
        array $dispositions,
        array $evidence,
        array $working,
    ): array {
        $again = [
            'evidence' => $this->inventory($evidenceRoot, $dispositions, 'evidence'),
            'working' => $this->inventory($workingRoot, $dispositions, 'working'),
        ];

        foreach (['evidence' => $evidence, 'working' => $working] as $role => $first) {
            if (! hash_equals($first['inventory_hash'], $again[$role]['inventory_hash'])) {
                throw new RuntimeException(
                    "The {$role} source copy changed while it was being verified; nothing was signed.",
                );
            }
        }

        return [
            'evidence_inventory_hash' => $again['evidence']['inventory_hash'],
            'working_inventory_hash' => $again['working']['inventory_hash'],
        ];
    }

    /** @param array<string, mixed> $custody */
    private function validateCustody(array $custody, string $signingKey): void
    {
        if ($signingKey === '') {
            throw new RuntimeException('Historic source evidence signing key is not configured.');
        }

        $expectedKeys = [
            'format', 'version', 'batch_key', 'physical_source', 'copies',
            'capacity_plan', 'malware_scan', 'retention', 'dispositions', 'signature',
        ];
        $actualKeys = array_keys($custody);
        sort($expectedKeys);
        sort($actualKeys);

        if ($expectedKeys !== $actualKeys
            || $custody['format'] !== 'crockenhill-historic-source-custody'
            || $custody['version'] !== self::Version
            || ! is_string($custody['batch_key'])
            || trim($custody['batch_key']) === '') {
            throw new RuntimeException('Historic source custody artifact has an unsupported or incomplete schema.');
        }

        $source = $custody['physical_source'];

        if (! is_array($source)
            || ! is_string($source['device_identity'] ?? null)
            || ! is_string($source['volume_identity'] ?? null)
            || ! is_string($source['filesystem'] ?? null)
            || ! is_string($source['health_report_sha256'] ?? null)
            || ($source['read_error_count'] ?? null) !== 0
            || ! is_array($source['mount'] ?? null)
            || ($source['mount']['read_only'] ?? null) !== true
            || ($source['mount']['write_probe_failed'] ?? null) !== true
            || ($source['mount']['noexec'] ?? null) !== true
            || ($source['mount']['nosuid'] ?? null) !== true
            || ($source['mount']['nodev'] ?? null) !== true) {
            throw new RuntimeException('Physical source identity, health and proven read-only mount controls are incomplete.');
        }

        $this->exactKeys($source, [
            'device_identity', 'volume_identity', 'filesystem', 'health_report_sha256',
            'read_error_count', 'mount',
        ], 'physical source');
        $mount = $source['mount'];

        $this->exactKeys($mount, [
            'read_only', 'write_probe_failed', 'noexec', 'nosuid', 'nodev',
        ], 'physical source mount');

        $this->hash($source['health_report_sha256'], 'physical source health report');

        $copies = $custody['copies'];

        if (! is_array($copies)
            || ! is_array($copies['evidence'] ?? null)
            || ! is_array($copies['working'] ?? null)
            || ! is_string($copies['evidence']['storage_identity'] ?? null)
            || ! is_string($copies['working']['storage_identity'] ?? null)
            || $copies['evidence']['storage_identity'] === $copies['working']['storage_identity']
            || ($copies['evidence']['protected_read_only'] ?? null) !== true
            || ($copies['working']['protected_read_only'] ?? null) !== true) {
            throw new RuntimeException('Two independently identified, protected source copies are required.');
        }
        $this->exactKeys($copies, ['evidence', 'working'], 'source copies');

        foreach ($copies as $role => $copy) {
            if (! is_array($copy)) {
                throw new RuntimeException("{$role} source copy evidence is invalid.");
            }

            /**
             * `failure_domain` is version 2's addition. It is a *claim* like the
             * rest, and the point is that it is now compared: two copies whose
             * declared storage identities differ but whose observed failure
             * domains match are one copy with two names.
             */
            $this->exactKeys($copy, [
                'storage_identity', 'failure_domain', 'protected_read_only', 'inventory_hash',
            ], "{$role} source copy");
            $this->hash($copy['inventory_hash'], "{$role} source copy inventory");
        }

        $malwareScan = $custody['malware_scan'];

        if (! is_array($malwareScan)
            || ($malwareScan['status'] ?? null) !== 'clean'
            || ! is_string($malwareScan['report_sha256'] ?? null)) {
            throw new RuntimeException('A clean, checksummed malware scan is required before processing.');
        }

        $this->exactKeys($malwareScan, [
            'status', 'engine', 'definitions_at', 'scanned_at', 'report_sha256',
        ], 'malware scan');

        $this->hash($malwareScan['report_sha256'], 'malware scan report');

        $retention = $custody['retention'];

        if (! is_array($retention)
            || ! is_string($retention['owner'] ?? null)
            || trim($retention['owner']) === ''
            || ! is_string($retention['retain_until'] ?? null)
            || ($retention['destruction_requires_acceptance'] ?? null) !== true) {
            throw new RuntimeException('Historic source custody retention owner/window is incomplete.');
        }

        $this->exactKeys($retention, [
            'owner', 'retain_until', 'destruction_requires_acceptance',
        ], 'source retention');

        if (! is_array($custody['dispositions']) || $custody['dispositions'] === []) {
            throw new RuntimeException('Historic source custody has no whole-tree dispositions.');
        }

        if (! is_array($custody['signature'])) {
            throw new RuntimeException('Historic source custody signature is invalid.');
        }

        $this->exactKeys($custody['signature'], ['algorithm', 'key_id', 'digest'], 'custody signature');

        foreach ($custody['dispositions'] as $path => $disposition) {
            if (! is_string($path) || $path === '' || ! is_array($disposition)) {
                throw new RuntimeException('Historic source custody contains an invalid path disposition.');
            }

            $this->exactKeys($disposition, ['disposition', 'xattrs'], "source disposition {$path}");

            if (! is_array($disposition['xattrs'])) {
                throw new RuntimeException("Historic source custody xattrs are invalid for {$path}.");
            }

            foreach ($disposition['xattrs'] as $name => $value) {
                if (! is_string($name) || ! is_string($value)) {
                    throw new RuntimeException("Historic source custody xattrs are invalid for {$path}.");
                }
            }
        }

        $signature = $custody['signature'];
        $expected = hash_hmac(
            'sha256',
            CanonicalJson::encode(array_diff_key($custody, ['signature' => true])),
            $signingKey,
        );

        if (($signature['algorithm'] ?? null) !== 'hmac-sha256'
            || ! is_string($signature['key_id'] ?? null)
            || ! is_string($signature['digest'] ?? null)
            || ! hash_equals($expected, $signature['digest'])) {
            throw new RuntimeException('Historic source custody signature is invalid.');
        }
    }

    private function hash(mixed $value, string $label): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new RuntimeException("Historic source {$label} must be a SHA-256 digest.");
        }
    }

    /** @param array<string, mixed> $inventory */
    private function validateCapacityPlan(mixed $capacity, array $inventory): void
    {
        if (! is_array($capacity)) {
            throw new RuntimeException('Historic source custody has no accepted capacity plan.');
        }

        $this->exactKeys($capacity, [
            'source_bytes', 'evidence_available_bytes', 'working_available_bytes',
            'temporary_required_bytes', 'staging_required_bytes', 'rollback_required_bytes',
            'approved_contingency_percent', 'accepted', 'planned_by', 'planned_at',
        ], 'source capacity plan');
        $entries = $inventory['entries'] ?? null;

        if (! is_array($entries)) {
            throw new RuntimeException('Historic source inventory has no capacity membership.');
        }

        $observedBytes = array_sum(array_map(
            static fn (array $entry): int => is_int($entry['byte_size']) ? $entry['byte_size'] : 0,
            $entries,
        ));

        foreach ([
            'source_bytes', 'evidence_available_bytes', 'working_available_bytes',
            'temporary_required_bytes', 'staging_required_bytes', 'rollback_required_bytes',
            'approved_contingency_percent',
        ] as $field) {
            if (! is_int($capacity[$field]) || $capacity[$field] < 0) {
                throw new RuntimeException("Historic source capacity plan {$field} must be a non-negative integer.");
            }
        }

        $workingRequirement = $capacity['source_bytes']
            + $capacity['temporary_required_bytes']
            + $capacity['staging_required_bytes']
            + $capacity['rollback_required_bytes'];
        $workingRequirement += (int) ceil(
            $workingRequirement * ($capacity['approved_contingency_percent'] / 100),
        );

        if ($capacity['source_bytes'] !== $observedBytes
            || $capacity['evidence_available_bytes'] < $observedBytes
            || $capacity['working_available_bytes'] < $workingRequirement
            || $capacity['accepted'] !== true
            || ! is_string($capacity['planned_by']) || trim($capacity['planned_by']) === ''
            || ! is_string($capacity['planned_at']) || trim($capacity['planned_at']) === '') {
            throw new RuntimeException('Historic source custody capacity plan is insufficient or unaccepted.');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $keys
     */
    private function exactKeys(array $value, array $keys, string $label): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        if ($actual !== $keys) {
            throw new RuntimeException("Historic {$label} has missing or unknown fields.");
        }
    }
}
