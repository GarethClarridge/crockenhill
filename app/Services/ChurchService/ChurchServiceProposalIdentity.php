<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\ChurchServiceMergeProposal;
use RuntimeException;

class ChurchServiceProposalIdentity
{
    public function for(ChurchServiceMergeProposal $proposal): string
    {
        $record = $proposal->triggerSourceRecord;

        if ($record === null) {
            throw new RuntimeException('A convergence proposal must have a trigger source record.');
        }

        return implode('|', [
            $record->source->value,
            $record->source_key,
            $record->revision_hash,
            $proposal->proposed_hash,
        ]);
    }
}
