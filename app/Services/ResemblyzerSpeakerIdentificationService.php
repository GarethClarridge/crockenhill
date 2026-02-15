<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SpeakerIdentificationInterface;
use App\Data\SpeakerEmbeddingResult;
use App\Data\SpeakerMatchResult;
use App\Models\SpeakerProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ResemblyzerSpeakerIdentificationService implements SpeakerIdentificationInterface
{
    public function extractEmbedding(string $audioPath): SpeakerEmbeddingResult
    {
        $absolutePath = $this->resolveAbsolutePath($audioPath);

        if (! file_exists($absolutePath)) {
            return SpeakerEmbeddingResult::failed("Audio file not found: {$absolutePath}");
        }

        $pythonPath = config('media-processing.speaker_identification.python_path', 'python3');
        $scriptPath = config('media-processing.speaker_identification.script_path');
        $duration = config('media-processing.speaker_identification.extraction_duration', 60);

        if (! $scriptPath || ! file_exists($scriptPath)) {
            return SpeakerEmbeddingResult::failed("Extraction script not found: {$scriptPath}");
        }

        try {
            $result = Process::timeout(120)->run([
                $pythonPath,
                $scriptPath,
                'extract',
                $absolutePath,
                '--duration',
                (string) $duration,
            ]);

            if (! $result->successful()) {
                Log::error('Speaker embedding extraction failed', [
                    'exit_code' => $result->exitCode(),
                    'stderr' => $result->errorOutput(),
                    'audio_path' => $audioPath,
                ]);

                return SpeakerEmbeddingResult::failed('Extraction script failed: '.$result->errorOutput());
            }

            $output = json_decode($result->output(), true);

            if (! is_array($output) || ! isset($output['embedding']) || ! is_array($output['embedding'])) {
                return SpeakerEmbeddingResult::failed('Invalid extraction script output');
            }

            return SpeakerEmbeddingResult::success(
                embedding: $output['embedding'],
                durationUsed: (float) ($output['duration_used'] ?? $duration),
            );
        } catch (\Exception $e) {
            Log::error('Speaker embedding extraction exception', [
                'error' => $e->getMessage(),
                'audio_path' => $audioPath,
            ]);

            return SpeakerEmbeddingResult::failed('Extraction exception: '.$e->getMessage());
        }
    }

    public function identify(string $audioPath, Collection $profiles): SpeakerMatchResult
    {
        if ($profiles->isEmpty()) {
            return SpeakerMatchResult::noProfiles();
        }

        $embeddingResult = $this->extractEmbedding($audioPath);

        if (! $embeddingResult->success || $embeddingResult->embedding === null) {
            return SpeakerMatchResult::error(
                'Embedding extraction failed: '.($embeddingResult->errorMessage ?? 'Unknown error')
            );
        }

        $scores = [];
        foreach ($profiles as $profile) {
            $scores[$profile->id] = $this->cosineSimilarity(
                $embeddingResult->embedding,
                $profile->centroid_embedding
            );
        }

        arsort($scores);
        $sortedIds = array_keys($scores);

        $topProfileId = $sortedIds[0];
        $topScore = $scores[$topProfileId];
        $secondScore = count($sortedIds) > 1 ? $scores[$sortedIds[1]] : null;

        /** @var SpeakerProfile $topProfile */
        $topProfile = $profiles->firstWhere('id', $topProfileId);

        $acceptThreshold = $topProfile->getEffectiveAcceptThreshold();
        $marginThreshold = $topProfile->getEffectiveMarginThreshold();

        $passesAccept = $topScore >= $acceptThreshold;
        $passesMargin = $secondScore === null || ($topScore - $secondScore) >= $marginThreshold;

        Log::info('Speaker identification result', [
            'top_profile_id' => $topProfileId,
            'top_score' => $topScore,
            'second_score' => $secondScore,
            'accept_threshold' => $acceptThreshold,
            'margin_threshold' => $marginThreshold,
            'passes_accept' => $passesAccept,
            'passes_margin' => $passesMargin,
        ]);

        if ($passesAccept && $passesMargin) {
            return SpeakerMatchResult::matched(
                profile: $topProfile,
                topScore: $topScore,
                secondScore: $secondScore,
                allScores: $scores,
            );
        }

        $reason = ! $passesAccept
            ? "Top score {$topScore} below accept threshold {$acceptThreshold}"
            : 'Margin '.($topScore - $secondScore)." below margin threshold {$marginThreshold}";

        return SpeakerMatchResult::noMatch(
            topScore: $topScore,
            secondScore: $secondScore,
            allScores: $scores,
            reason: $reason,
        );
    }

    public function updateProfile(SpeakerProfile $profile, array $approvedEmbeddings): SpeakerProfile
    {
        if (empty($approvedEmbeddings)) {
            return $profile;
        }

        $centroid = $this->computeCentroid($approvedEmbeddings);

        $profile->update([
            'centroid_embedding' => $centroid,
            'sample_count' => count($approvedEmbeddings),
        ]);

        return $profile->fresh() ?? $profile;
    }

    /**
     * Compute cosine similarity between two embedding vectors.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dimensions = min(count($a), count($b));

        if ($dimensions === 0) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $dimensions; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);

        if ($denominator === 0.0) {
            return 0.0;
        }

        return $dotProduct / $denominator;
    }

    /**
     * Compute the centroid (element-wise average) of a set of embeddings.
     *
     * @param  list<array<int, float>>  $embeddings
     * @return list<float>
     */
    private function computeCentroid(array $embeddings): array
    {
        $count = count($embeddings);
        $dimensions = count($embeddings[0]);
        $centroid = array_fill(0, $dimensions, 0.0);

        foreach ($embeddings as $embedding) {
            for ($i = 0; $i < $dimensions; $i++) {
                $centroid[$i] += $embedding[$i];
            }
        }

        for ($i = 0; $i < $dimensions; $i++) {
            $centroid[$i] /= $count;
        }

        return $centroid;
    }

    private function resolveAbsolutePath(string $audioPath): string
    {
        $sermonDisk = config('media-processing.storage.sermon_disk', 'public');

        return Storage::disk($sermonDisk)->path($audioPath);
    }
}
