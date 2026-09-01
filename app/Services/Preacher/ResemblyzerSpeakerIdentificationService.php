<?php

declare(strict_types=1);

namespace App\Services\Preacher;

use App\Contracts\SpeakerIdentificationInterface;
use App\Data\SpeakerEmbeddingResult;
use App\Data\SpeakerMatchResult;
use App\Models\SpeakerProfile;
use App\Support\MediaAssetPath;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Service for speaker identification using Resemblyzer.
 *
 * This service leverages a Python-based implementation of Resemblyzer to
 * extract voice embeddings (fingerprints) from audio files. These embeddings
 * are then used to identify speakers by comparing them against known
 * speaker profiles using cosine similarity.
 */
class ResemblyzerSpeakerIdentificationService implements SpeakerIdentificationInterface
{
    /**
     * Extract a speaker embedding (voice fingerprint) from an audio file.
     *
     * Delegates to a Python script that uses Resemblyzer to process a
     * segment of the audio file and return a high-dimensional vector
     * representing the speaker's vocal characteristics.
     *
     * @param  string  $audioPath  Disk-relative path to the audio file
     * @param  string|null  $disk  Override the storage disk (defaults to configured sermon disk)
     * @return SpeakerEmbeddingResult The extracted embedding vector or a failure reason
     */
    public function extractEmbedding(string $audioPath, ?string $disk = null): SpeakerEmbeddingResult
    {
        $preparedAudio = $this->prepareAudioForExtraction($audioPath, $disk);
        $absolutePath = $preparedAudio['absolute_path'];
        $tempLocalPath = $preparedAudio['temp_local_path'];

        $pythonPath = config('media-processing.speaker_identification.python_path', 'python3');
        $scriptPath = config('media-processing.speaker_identification.script_path');
        $duration = config('media-processing.speaker_identification.extraction_duration', 60);

        try {
            if (! file_exists($absolutePath)) {
                return SpeakerEmbeddingResult::failed("Audio file not found: {$audioPath}");
            }

            if (! $scriptPath || ! file_exists($scriptPath)) {
                return SpeakerEmbeddingResult::failed("Extraction script not found: {$scriptPath}");
            }

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

            $output = $this->decodeExtractionOutput($result->output());

            if (! is_array($output) || ! isset($output['embedding']) || ! is_array($output['embedding'])) {
                Log::warning('Speaker embedding extraction returned invalid output', [
                    'audio_path' => $audioPath,
                    'stdout_snippet' => mb_substr(trim($result->output()), 0, 500),
                ]);

                return SpeakerEmbeddingResult::failed('Invalid extraction script output');
            }

            $embedding = array_values(array_map('floatval', $output['embedding']));

            return SpeakerEmbeddingResult::success(
                embedding: $embedding,
                durationUsed: (float) ($output['duration_used'] ?? $duration),
            );
        } catch (\Exception $e) {
            Log::error('Speaker embedding extraction exception', [
                'error' => $e->getMessage(),
                'audio_path' => $audioPath,
            ]);

            return SpeakerEmbeddingResult::failed('Extraction exception: '.$e->getMessage());
        } finally {
            if ($tempLocalPath !== null && Storage::disk('local')->exists($tempLocalPath)) {
                Storage::disk('local')->delete($tempLocalPath);
            }
        }
    }

    /**
     * Identify the speaker in an audio file by comparing it against known profiles.
     *
     * Extracts an embedding from the audio and calculates the cosine
     * similarity between it and the centroid embeddings of each provided
     * profile. The best match is returned if it exceeds configured
     * confidence and margin thresholds.
     *
     * @param  string  $audioPath  Disk-relative path to the audio file
     * @param  Collection<int, SpeakerProfile>  $profiles  Known speaker profiles to compare against
     * @return SpeakerMatchResult The match result, including top score and matched profile
     */
    public function identify(string $audioPath, Collection $profiles, ?string $disk = null): SpeakerMatchResult
    {
        if ($profiles->isEmpty()) {
            return SpeakerMatchResult::noProfiles();
        }

        $embeddingResult = $this->extractEmbedding($audioPath, $disk);

        if (! $embeddingResult->success || $embeddingResult->embedding === null) {
            return SpeakerMatchResult::error(
                'Embedding extraction failed: '.($embeddingResult->errorMessage ?? 'Unknown error')
            );
        }

        $scores = [];
        foreach ($profiles as $profile) {
            $scores[$profile->id] = $this->cosineSimilarity(
                $embeddingResult->embedding,
                array_values($profile->centroid_embedding)
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

        $candidates = SpeakerMatchResult::namedCandidates($profiles, $scores);

        if ($passesAccept && $passesMargin) {
            return SpeakerMatchResult::matched(
                profile: $topProfile,
                topScore: $topScore,
                secondScore: $secondScore,
                allScores: $scores,
                candidates: $candidates,
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
            candidates: $candidates,
        );
    }

    /**
     * Update a speaker profile with a new centroid computed from approved embeddings.
     *
     * Recalculates the element-wise average of multiple embedding vectors
     * to refine the speaker's vocal fingerprint.
     *
     * @param  SpeakerProfile  $profile  The profile to update
     * @param  list<array<int, float>>  $approvedEmbeddings  A collection of validated embedding vectors
     * @return SpeakerProfile The updated speaker profile
     */
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

        return array_values($centroid);
    }

    /**
     * @return array{absolute_path:string,temp_local_path:string|null}
     */
    private function prepareAudioForExtraction(string $audioPath, ?string $disk = null): array
    {
        $sermonDisk = $disk ?? MediaAssetPath::disk();
        $sermonFilesystem = Storage::disk($sermonDisk);

        if ($this->usesRemoteStorage($sermonDisk)) {
            return $this->downloadRemoteAudioToLocalTemp($sermonFilesystem, $audioPath);
        }

        return [
            'absolute_path' => $sermonFilesystem->path($audioPath),
            'temp_local_path' => null,
        ];
    }

    /**
     * @return array{absolute_path:string,temp_local_path:string|null}
     */
    private function downloadRemoteAudioToLocalTemp(FilesystemAdapter $filesystem, string $audioPath): array
    {
        if (! $filesystem->exists($audioPath)) {
            return [
                'absolute_path' => '',
                'temp_local_path' => null,
            ];
        }

        $extension = pathinfo($audioPath, PATHINFO_EXTENSION);
        $tempLocalPath = 'temp/speaker-identification/'.Str::uuid().($extension ? ".{$extension}" : '.mp3');

        Storage::disk('local')->makeDirectory(dirname($tempLocalPath));

        $stream = $filesystem->readStream($audioPath);
        if (! is_resource($stream)) {
            return [
                'absolute_path' => '',
                'temp_local_path' => null,
            ];
        }

        $written = Storage::disk('local')->writeStream($tempLocalPath, $stream);
        fclose($stream);

        if ($written === false) {
            return [
                'absolute_path' => '',
                'temp_local_path' => null,
            ];
        }

        return [
            'absolute_path' => Storage::disk('local')->path($tempLocalPath),
            'temp_local_path' => $tempLocalPath,
        ];
    }

    private function usesRemoteStorage(string $disk): bool
    {
        $driver = (string) config("filesystems.disks.{$disk}.driver", 'local');

        return $driver === 's3';
    }

    /**
     * Parse extraction script output, tolerating informational lines before JSON.
     *
     * @return array<string, mixed>|null
     */
    private function decodeExtractionOutput(string $rawOutput): ?array
    {
        $decoded = json_decode($rawOutput, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $lines = preg_split('/\R/', trim($rawOutput)) ?: [];

        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
