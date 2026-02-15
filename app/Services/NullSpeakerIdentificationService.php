<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SpeakerIdentificationInterface;
use App\Data\SpeakerEmbeddingResult;
use App\Data\SpeakerMatchResult;
use App\Models\SpeakerProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NullSpeakerIdentificationService implements SpeakerIdentificationInterface
{
    public function extractEmbedding(string $audioPath): SpeakerEmbeddingResult
    {
        Log::debug('NullSpeakerIdentificationService: extractEmbedding called', [
            'audio_path' => $audioPath,
        ]);

        return SpeakerEmbeddingResult::failed('Null provider: speaker identification not configured');
    }

    public function identify(string $audioPath, Collection $profiles): SpeakerMatchResult
    {
        Log::debug('NullSpeakerIdentificationService: identify called', [
            'audio_path' => $audioPath,
            'profile_count' => $profiles->count(),
        ]);

        return SpeakerMatchResult::noMatch(reason: 'Null provider: speaker identification not configured');
    }

    public function updateProfile(SpeakerProfile $profile, array $approvedEmbeddings): SpeakerProfile
    {
        Log::debug('NullSpeakerIdentificationService: updateProfile called', [
            'profile_id' => $profile->id,
            'embedding_count' => count($approvedEmbeddings),
        ]);

        return $profile;
    }
}
