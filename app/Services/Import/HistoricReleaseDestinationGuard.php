<?php

declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;

/**
 * Plan §4.2.1: the compensating control HIR-D2 left HIR7 to carry.
 *
 * HIR-D2 demoted the storage anchor so that only the **database** anchor arms
 * the production guard. It had to: `.env` sets `SERMON_STORAGE_DISK=do_spaces`
 * with `DO_SPACES_BUCKET=crockenhill`, so local dev's public sermon disk *is*
 * the production bucket, and an OR-ed storage anchor would have classified every
 * developer machine as production and refused the §13.5 rehearsal.
 *
 * The accepted residual risk was that a local historic release still writes to
 * the production public bucket and the guard does not stop it. This is where it
 * stops instead — not on the guard, which would re-arm the anchor and gate the
 * rehearsal, but at the point of writing.
 *
 * The rule: outside production, refuse to write to a destination whose resolved
 * identity matches the recorded production storage anchor. The rehearsal stays
 * usable because it publishes to a rehearsal disk, and "a local run published to
 * the production bucket" becomes an error rather than a silent success.
 *
 * The override is separately named on purpose. It is not the production import
 * approval, not the release authorisation and not a flag on the guard, so
 * nothing that authorises the rest of the operation can switch this off as a
 * side effect.
 *
 * Delete alongside the release ledger once the accepted public release and
 * rollback observation window have closed (G9/WP10).
 */
class HistoricReleaseDestinationGuard
{
    public function __construct(
        private readonly HistoricImportProductionGuard $guard,
        private readonly HistoricImportResourceIdentity $resources,
    ) {}

    /**
     * @throws RuntimeException when a non-production process would publish to
     *                          the recorded production destination
     */
    public function assertWritable(string $disk): void
    {
        if (! $this->matchesProductionStorage($disk)) {
            return;
        }

        if ($this->guard->guardsCurrentEnvironment()) {
            return;
        }

        if (config('church.historic_corpus.allow_non_production_release_destination') === true) {
            return;
        }

        throw new RuntimeException(
            "Release destination '{$disk}' resolves to the recorded production storage anchor, but this process is "
            .'not the production target. Publish to a rehearsal disk, or set '
            .'HISTORIC_IMPORT_ALLOW_NON_PRODUCTION_RELEASE_DESTINATION=true to accept writing production-visible '
            .'bytes from here.'
        );
    }

    /**
     * Whether this disk is the one the recorded production storage anchor names.
     *
     * An absent or malformed anchor answers false: an environment that has not
     * recorded one cannot know it is about to write to production, and refusing
     * every release on that basis would gate the rehearsal on configuration only
     * a production deploy supplies. That is the same reasoning HIR1 applied to
     * an absent database anchor, and the release-candidate baseline asserts the
     * anchors are configured.
     */
    private function matchesProductionStorage(string $disk): bool
    {
        $anchor = config('church.historic_corpus.production_storage_anchor');

        if (! is_string($anchor) || preg_match('/\A[a-f0-9]{64}\z/', $anchor) !== 1) {
            return false;
        }

        try {
            return hash_equals($anchor, $this->resources->anchorFor($disk));
        } catch (\Throwable) {
            return false;
        }
    }
}
