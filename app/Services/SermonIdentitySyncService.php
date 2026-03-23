<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use Illuminate\Support\Str;

class SermonIdentitySyncService
{
    public function __construct(
        private readonly ScriptureReferenceResolver $scriptureReferenceResolver,
    ) {}

    public function syncForPersistence(Sermon $sermon): void
    {
        $this->syncPreacherIdentity($sermon);
        $this->syncScriptureIdentity($sermon);
    }

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
        if ($sermon->preacher_id !== null) {
            $preacher = Preacher::query()->find($sermon->preacher_id);

            if ($preacher instanceof Preacher) {
                $sermon->preacher = $preacher->name;
            }

            return;
        }

        $matchedPreacher = $this->matchExistingPreacher($sermon->preacher);

        if ($matchedPreacher instanceof Preacher) {
            $sermon->preacher_id = $matchedPreacher->id;
            $sermon->preacher = $matchedPreacher->name;
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
