<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ServiceSectionMetadata;
use App\Data\TalkTypeInput;
use App\Data\TalkTypeProposal;
use App\Models\ServiceSection;
use App\Services\ChurchService\TalkTypeInputBuilder;

/** Stores an already computed proposal; performs no classification or processing. */
class ProposeTalkType
{
    public function __construct(private readonly TalkTypeInputBuilder $inputs) {}

    /** @return 'stored'|'unchanged'|'stale'|'ineligible' */
    public function persist(TalkTypeInput $input, TalkTypeProposal $proposal): string
    {
        $connection = (new ServiceSection)->getConnection();

        return $connection->transaction(function () use ($input, $proposal): string {
            $section = ServiceSection::query()->find($input->sectionId);
            if ($section === null || $input->ineligibleReasons !== []
                || ! is_string($input->versions['classifier'] ?? null) || trim($input->versions['classifier']) === '') {
                return 'ineligible';
            }
            $fresh = $this->inputs->build($section, $input->versions, lockForUpdate: true);
            if ($fresh->ineligibleReasons !== []) {
                return 'ineligible';
            }
            if (! hash_equals($input->inputHash(), $fresh->inputHash())) {
                return 'stale';
            }
            $section = ServiceSection::query()->lockForUpdate()->findOrFail($input->sectionId);
            $metadata = $section->metadata?->toArray() ?? [];
            $talk = is_array($metadata['talk_type'] ?? null) ? $metadata['talk_type'] : [];
            if (($talk['input_hash'] ?? null) === $fresh->inputHash() && in_array($talk['proposal_status'] ?? null, ['completed', 'unresolved'], true)) {
                return 'unchanged';
            }
            $keys = array_merge(array_keys($proposal->toArray()), ['input_hash', 'versions', 'classifier_version', 'input_provenance', 'proposed_at']);
            if (isset($talk['input_hash'])) {
                $talk['proposal_history'][] = array_intersect_key($talk, array_flip($keys));
            }
            if (! array_key_exists('structure_proposed', $talk)) {
                $talk['structure_proposed'] = $fresh->structureProposed;
            }
            $metadata['talk_type'] = array_replace($talk, $proposal->toArray(), [
                'input_hash' => $fresh->inputHash(), 'versions' => $fresh->versions,
                'classifier_version' => $fresh->versions['classifier'] ?? null,
                'input_provenance' => $fresh->provenance, 'proposed_at' => now()->toIso8601String(),
            ]);
            $section->metadata = ServiceSectionMetadata::fromArray($metadata);
            $section->save();

            return 'stored';
        });
    }
}
