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
     *
     * @param  string  $audioPath  Disk-relative path to the audio file
     * @param  string|null  $disk  Override the storage disk (defaults to sermon_disk config)
     */
    public function extractEmbedding(string $audioPath, ?string $disk = null): SpeakerEmbeddingResult;

    /**
     * Identify the speaker in an audio file by comparing against known profiles.
     *
     * @param  Collection<int, SpeakerProfile>  $profiles
     * @param  string|null  $disk  Override the storage disk (defaults to sermon_disk config).
     *                             A promoted historic sermon's audio lives on its own asset
     *                             disk, not the configured staging one.
     */
    public function identify(string $audioPath, Collection $profiles, ?string $disk = null): SpeakerMatchResult;

    /**
     * Update a speaker profile's centroid from approved embedding vectors.
     *
     * @param  list<array<int, float>>  $approvedEmbeddings
     */
    public function updateProfile(SpeakerProfile $profile, array $approvedEmbeddings): SpeakerProfile;
}
