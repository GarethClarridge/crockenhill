<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The disposition of one historic hymn workbook row against what the database already holds.
 *
 * F62 requires a rerun to distinguish these rather than counting a freshly computed catalogue
 * match as though it had been persisted. Every value is decided in a read-only planning pass, so
 * a blocking outcome refuses the run before the first write.
 */
enum HistoricSongUsageRowOutcome: string
{
    case Created = 'created';
    case Unchanged = 'unchanged';
    case ResolutionApplied = 'resolution_applied';
    case ResolutionAvailable = 'resolution_available';
    case ResolutionConflict = 'resolution_conflict';
    case CanonicalLinkApplied = 'canonical_link_applied';
    case CanonicalLinkAvailable = 'canonical_link_available';
    case CanonicalLinkAmbiguous = 'canonical_link_ambiguous';
    case SourceDrift = 'source_drift';

    /**
     * Whether observing this outcome must refuse the whole run before anything is written.
     *
     * Both blocking values mean the world changed underneath an approved workbook in a way no
     * automatic rule can settle: the stored occurrence names a different song than the catalogue
     * now resolves, or an immutable source field moved without moving the fingerprint.
     */
    public function blocksRun(): bool
    {
        return match ($this) {
            self::ResolutionConflict, self::SourceDrift => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Unchanged => 'Unchanged',
            self::ResolutionApplied => 'Resolution applied',
            self::ResolutionAvailable => 'Resolution available (not authorised)',
            self::ResolutionConflict => 'Resolution conflict',
            self::CanonicalLinkApplied => 'Canonical link applied',
            self::CanonicalLinkAvailable => 'Canonical link available (not authorised)',
            self::CanonicalLinkAmbiguous => 'Canonical link ambiguous (more than one item)',
            self::SourceDrift => 'Source drift',
        };
    }
}
