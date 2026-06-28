<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Enums\PreacherSource;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\Scripture\ScriptureReferenceResolver;
use Illuminate\Support\Str;

/**
 * Service for coordinating the resolution and synchronization of preacher
 * and scripture identities for Sermon models.
 *
 * Ensures that denormalized preacher names match registered Preacher records
 * and that scripture references are consistently linked to canonical
 * ScripturePassage entities before persistence.
 */
class SermonIdentitySyncService
{
    public function __construct(
        private readonly ScriptureReferenceResolver $scriptureReferenceResolver,
    ) {}

    /**
     * Ensure identity consistency for preacher and scripture before saving.
     *
     * Delegates to specialized internal methods to sync denormalized
     * preacher names with IDs and scripture references with passages.
     *
     * @param  Sermon  $sermon  The sermon model to synchronize
     */
    public function syncForPersistence(Sermon $sermon): void
    {
        $this->syncPreacherIdentity($sermon);
        $this->syncScriptureIdentity($sermon);
    }

    /**
     * Retroactively link sermons to a preacher when a new alias is registered.
     *
     * Identifies unassigned sermons matching the alias name and updates them
     * with the canonical preacher identity. Utilizes database-specific
     * regex (on MySQL) to ensure whitespace-insensitive matching.
     *
     * @param  PreacherAlias  $alias  The newly registered preacher alias
     */
    public function backfillSermonsForAlias(PreacherAlias $alias): void
    {
        $preacher = $alias->preacher;

        if (! $preacher instanceof Preacher) {
            return;
        }

        $query = Sermon::query()->whereNull('preacher_id');

        // Normalize internal whitespace during matching.
        if (config('database.default') === 'mysql') {
            $query->whereRaw("LOWER(REGEXP_REPLACE(TRIM(preacher), '[[:space:]]+', ' ')) = ?", [$alias->alias]);
        } else {
            $query->whereRaw('LOWER(TRIM(preacher)) = ?', [$alias->alias]);
        }

        $query->update([
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'preacher_source' => PreacherSource::Manual->value,
            'needs_preacher_review' => false,
        ]);
    }

    /**
     * Resolve a raw scripture reference string to an existing passage.
     *
     * Implements a two-stage lookup: first attempts to match via a normalized
     * reference string (derived from the ScriptureReferenceResolver), then
     * falls back to a direct match on the display reference.
     *
     * @param  string|null  $rawReference  The scripture reference to resolve
     * @return ScripturePassage|null The matched passage or null if not found
     */
    public function findExistingScripturePassage(?string $rawReference): ?ScripturePassage
    {
        if (! is_string($rawReference)) {
            return null;
        }

        $reference = trim($rawReference);
        if ($reference === '') {
            return null;
        }

        $normalizedReference = $this->scriptureReferenceResolver->normalize($reference);
        if ($normalizedReference === null) {
            return null;
        }

        $bibleId = (string) config('services.api_bible.default_bible_id');

        $passage = ScripturePassage::query()
            ->when(
                $bibleId !== '',
                fn ($query) => $query->where('bible_id', $bibleId),
            )
            ->where('normalized_reference', $normalizedReference)
            ->first();

        if ($passage instanceof ScripturePassage) {
            return $passage;
        }

        return ScripturePassage::query()
            ->when(
                $bibleId !== '',
                fn ($query) => $query->where('bible_id', $bibleId),
            )
            ->where('display_reference', $reference)
            ->first();
    }

    private function syncPreacherIdentity(Sermon $sermon): void
    {
        $preacherIdChanged = $sermon->isDirty('preacher_id');
        $preacherNameChanged = $sermon->isDirty('preacher');

        // 1. Explicit ID change or constant authority: ID wins.
        // We sync the string cache from the ID, but skip this if the name string
        // was explicitly changed (allowing it to resolve to a new ID in step 2).
        if ($sermon->preacher_id !== null && ($preacherIdChanged || ! $preacherNameChanged)) {
            $preacher = Preacher::query()->find($sermon->preacher_id);

            if ($preacher instanceof Preacher && $sermon->preacher !== $preacher->name) {
                $sermon->preacher = $preacher->name;
            }

            // If the ID was the primary thing that changed, we are done.
            if ($preacherIdChanged) {
                return;
            }
        }

        // 2. Explicit name string change: try to resolve to a known identity.
        if ($preacherNameChanged) {
            $matchedPreacher = $this->matchExistingPreacher($sermon->preacher);

            if ($matchedPreacher instanceof Preacher) {
                $sermon->preacher_id = $matchedPreacher->id;
                $sermon->preacher = $matchedPreacher->name;
            } else {
                // Name changed to something unknown; decouple from the current identity.
                $sermon->preacher_id = null;
            }

            return;
        }

        // 3. Initial resolution or backfill for unassigned records.
        if ($sermon->preacher_id === null && trim($sermon->preacher) !== '') {
            $matchedPreacher = $this->matchExistingPreacher($sermon->preacher);

            if ($matchedPreacher instanceof Preacher) {
                $sermon->preacher_id = $matchedPreacher->id;
                $sermon->preacher = $matchedPreacher->name;
            }
        }
    }

    /**
     * Normalize the cache text around the canonical scripture owner.
     *
     * Deliberately does not resolve a free-text reference onto a passage when no
     * canonical `scripture_passage_id` is set. That lookup is performed
     * explicitly in write flows that opt into compatibility linking, while the
     * async enrichment path remains responsible for broader scripture backfill.
     */
    private function syncScriptureIdentity(Sermon $sermon): void
    {
        $trimmedReference = $this->trimReference($sermon->reference);
        $scripturePassageIdChanged = $sermon->isDirty('scripture_passage_id');

        if ($sermon->scripture_passage_id !== null && $scripturePassageIdChanged) {
            $passage = ScripturePassage::query()->find($sermon->scripture_passage_id);

            if ($passage instanceof ScripturePassage) {
                $sermon->reference = $this->canonicalPassageReference($passage);
            }

            return;
        }

        if ($sermon->isDirty('reference')) {
            if ($trimmedReference === null) {
                $sermon->reference = null;
                $sermon->scripture_passage_id = null;

                return;
            }

            if ($sermon->scripture_passage_id !== null) {
                $passage = ScripturePassage::query()->find($sermon->scripture_passage_id);

                if ($passage instanceof ScripturePassage) {
                    if ($this->referenceMatchesPassage($trimmedReference, $passage)) {
                        $sermon->reference = $this->canonicalPassageReference($passage);

                        return;
                    }

                    $sermon->scripture_passage_id = null;
                }
            }

            $sermon->reference = $trimmedReference;

            return;
        }

        if ($sermon->scripture_passage_id !== null) {
            $passage = ScripturePassage::query()->find($sermon->scripture_passage_id);

            if ($passage instanceof ScripturePassage) {
                $sermon->reference = $this->canonicalPassageReference($passage);
            }

            return;
        }

        if ($trimmedReference === null) {
            $sermon->reference = null;

            return;
        }

        $sermon->reference = $trimmedReference;
    }

    private function matchExistingPreacher(?string $rawPreacherName): ?Preacher
    {
        if (! is_string($rawPreacherName)) {
            return null;
        }

        $normalizedPreacherName = trim((string) preg_replace('/\s+/', ' ', $rawPreacherName));
        if ($normalizedPreacherName === '') {
            return null;
        }

        $preacher = Preacher::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($normalizedPreacherName)])
            ->first();

        if ($preacher instanceof Preacher) {
            return $preacher;
        }

        $alias = PreacherAlias::query()
            ->with('preacher')
            ->where('alias', Str::lower($normalizedPreacherName))
            ->first();

        return $alias?->preacher;
    }

    private function canonicalPassageReference(ScripturePassage $passage): string
    {
        return trim((string) ($passage->display_reference ?: $passage->normalized_reference));
    }

    private function trimReference(?string $reference): ?string
    {
        if (! is_string($reference)) {
            return null;
        }

        $trimmed = trim($reference);

        return $trimmed === '' ? null : $trimmed;
    }

    private function referenceMatchesPassage(string $reference, ScripturePassage $passage): bool
    {
        $canonicalReferences = array_filter([
            $this->trimReference($passage->display_reference),
            $this->trimReference($passage->normalized_reference),
        ]);

        if (in_array($reference, $canonicalReferences, true)) {
            return true;
        }

        $normalizedReference = $this->scriptureReferenceResolver->normalize($reference);

        return $normalizedReference !== null
            && in_array($normalizedReference, $canonicalReferences, true);
    }
}
