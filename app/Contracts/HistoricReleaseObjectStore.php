<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\HistoricReleaseObject;
use RuntimeException;

/**
 * The only way a release attempt touches a public destination.
 *
 * The boundary exists because of what HIR-D1 measured against the production
 * `crockenhill` bucket:
 *
 * - conditional create (`PutObject` with `IfNoneMatch: *`) is **enforced** — a
 *   second writer gets a 412 rather than overwriting;
 * - `DeleteObject` with `IfMatch` is **silently ignored** — the header is
 *   dropped and the object is deleted regardless of whose bytes it holds;
 * - `PutObject` returns a null `VersionId`, because bucket versioning is off.
 *
 * So exact-ownership deletion does not exist here, and an implementation must
 * refuse it rather than emulate it. A local fake that emulated conditional
 * delete would pass review, pass its own tests, and still destroy the winner's
 * bytes in production — the precise trap the plan's "no decision may be
 * inferred from a test fixture" warns about.
 *
 * `createIfAbsent()` must be a genuine conditional create. `exists()` followed
 * by `writeStream()` is two operations with a window between them, and that
 * window is the defect HIR7 closes.
 *
 * Delete alongside the release ledger after the accepted public release and
 * rollback observation window (G9/WP10).
 */
interface HistoricReleaseObjectStore
{
    /** The object already at this destination, or null when it is absent. */
    public function inspect(string $disk, string $path): ?HistoricReleaseObject;

    /**
     * Create the object only if the destination is absent.
     *
     * Returns the created object with `createdByThisAttempt` true, or — when
     * another writer got there first — the object that is already there, with
     * it false. The caller decides whether identical bytes are acceptable; this
     * never overwrites and never reports someone else's object as its own.
     *
     * @param  resource  $stream
     *
     * @throws RuntimeException when the destination refuses the write outright
     */
    public function createIfAbsent(string $disk, string $path, mixed $stream): HistoricReleaseObject;

    /**
     * Re-read the destination and confirm it holds exactly these bytes.
     *
     * @throws RuntimeException when the object is absent or differs
     */
    public function verify(string $disk, string $path, int $size, string $sha256): HistoricReleaseObject;

    /**
     * Whether this store can delete an exact version it created, and nothing
     * else. False everywhere HIR-D1 measured, and the reason failed objects
     * become retained orphans rather than deletions.
     */
    public function supportsExactVersionDelete(string $disk): bool;

    /**
     * @throws RuntimeException when exact-version deletion is unavailable, which
     *                          is every store this application has
     */
    public function deleteExactVersion(HistoricReleaseObject $object): void;
}
