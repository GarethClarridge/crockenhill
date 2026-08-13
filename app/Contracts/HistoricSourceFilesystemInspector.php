<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\HistoricSourceRootObservation;
use RuntimeException;

/**
 * The only place source-custody facts are *observed* rather than believed.
 *
 * HIR-D4 approved Darwin and production Linux implementations and nothing else:
 * an unknown platform, or a host that cannot expose a required fact, fails
 * closed. There is no "assume protected" flag, because a claim nobody could
 * check is what HIR4 exists to remove.
 *
 * Delete alongside the source-acquisition verifier once the archive is imported
 * and its custody artifacts have moved to long-term custody (G9/WP10).
 */
interface HistoricSourceFilesystemInspector
{
    /** Which platform this inspector observes, e.g. `Darwin` or `Linux`. */
    public function platform(): string;

    /**
     * Mount, device and write-protection facts for one copy's root.
     *
     * @throws RuntimeException when a required fact cannot be observed
     */
    public function observeRoot(string $root): HistoricSourceRootObservation;

    /**
     * Whether this host can read extended attributes at all.
     *
     * False is a fact, not a failure: a custody artifact that claims no
     * attributes needs none observed. It becomes a refusal only when the
     * artifact claims one, because then the claim is unverifiable — and an
     * unverifiable claim is exactly what HIR4 removes.
     */
    public function supportsExtendedAttributes(): bool;

    /**
     * The extended attributes actually present on a path.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException when the attributes cannot be read at all, which
     *                          is different from a path that has none
     */
    public function xattrs(string $path): array;
}
