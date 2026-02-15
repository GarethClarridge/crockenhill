<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\SpeakerEmbeddingResult;
use App\Data\SpeakerMatchResult;
use App\Models\SpeakerProfile;
use Illuminate\Support\Collection;

interface SpeakerIdentificationInterface
{
    /**
     * Extract a speaker embedding from an audio file.
     */
    public function extractEmbedding(string $audioPath): SpeakerEmbeddingResult;

    /**
     * Identify the speaker in an audio file by comparing against known profiles.
     *
     * @param  Collection<int, SpeakerProfile>  $profiles
     */
    public function identify(string $audioPath, Collection $profiles): SpeakerMatchResult;

    /**
     * Update a speaker profile's centroid from approved embedding vectors.
     *
     * @param  list<array<int, float>>  $approvedEmbeddings
     */
    public function updateProfile(SpeakerProfile $profile, array $approvedEmbeddings): SpeakerProfile;
}
