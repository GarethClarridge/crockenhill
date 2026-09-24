<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Models\MediaProcessingLog;
use App\Support\CanonicalJson;
use App\Support\RepositoryCommit;
use RuntimeException;

/**
 * The before-state of a corpus re-run batch: an exact membership, its hash, the commit it was
 * taken on, and each run's {@see HistoricRerunState}.
 *
 * The file is the batch's frozen membership as well as its baseline. The diff reads its runs
 * back, and the dispatch route refuses a run the snapshot does not hold, so nothing can be
 * re-run without a record of what it replaced.
 *
 * Delete with the corpus re-run's other instruments once its batches are accepted.
 */
final readonly class HistoricRerunSnapshot
{
    public const KIND = 'historic_rerun_snapshot';

    /**
     * @param  list<int>  $membership
     * @param  array<int, array<string, mixed>>  $runs
     */
    public function __construct(
        public string $takenAt,
        public ?string $gitCommit,
        public array $membership,
        public string $membershipSha256,
        public array $runs,
        public ?string $fileSha256 = null,
    ) {}

    /**
     * @param  list<int>  $runIds
     */
    public static function take(array $runIds, HistoricRerunState $state): self
    {
        $membership = self::membership($runIds);
        $runs = [];

        foreach ($membership as $runId) {
            $run = MediaProcessingLog::find($runId);

            if (! $run instanceof MediaProcessingLog) {
                throw new RuntimeException(sprintf('run #%d not found; a snapshot holds exact membership only', $runId));
            }

            $runs[$runId] = $state->capture($run);
        }

        return new self(
            takenAt: now()->toIso8601String(),
            gitCommit: RepositoryCommit::current(),
            membership: $membership,
            membershipSha256: CanonicalJson::hash($membership),
            runs: $runs,
        );
    }

    public static function fromFile(string $path): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            throw new RuntimeException("Snapshot {$path} could not be read.");
        }

        $data = json_decode($contents, true);

        if (! is_array($data) || ($data['kind'] ?? null) !== self::KIND) {
            throw new RuntimeException("{$path} is not a historic re-run snapshot.");
        }

        if (($data['version'] ?? null) !== HistoricRerunState::VERSION) {
            throw new RuntimeException(sprintf('Snapshot %s captured shape version %s; this code captures version %d.', $path, var_export($data['version'] ?? null, true), HistoricRerunState::VERSION));
        }

        $membership = self::membership((array) ($data['membership'] ?? []));

        if (! hash_equals(CanonicalJson::hash($membership), (string) ($data['membership_sha256'] ?? ''))) {
            throw new RuntimeException("Snapshot {$path} membership does not match its recorded hash.");
        }

        $runs = [];

        foreach ((array) ($data['runs'] ?? []) as $runId => $run) {
            if (is_array($run)) {
                $runs[(int) $runId] = $run;
            }
        }

        if (array_diff($membership, array_keys($runs)) !== []) {
            throw new RuntimeException("Snapshot {$path} does not hold a state for every member.");
        }

        return new self(
            takenAt: (string) ($data['taken_at'] ?? ''),
            gitCommit: is_string($data['git_commit'] ?? null) ? $data['git_commit'] : null,
            membership: $membership,
            membershipSha256: (string) $data['membership_sha256'],
            runs: $runs,
            fileSha256: hash('sha256', $contents),
        );
    }

    public function holds(int $runId): bool
    {
        return isset($this->runs[$runId]);
    }

    public function encode(): string
    {
        return CanonicalJson::encodeReadable([
            'kind' => self::KIND,
            'version' => HistoricRerunState::VERSION,
            'taken_at' => $this->takenAt,
            'git_commit' => $this->gitCommit,
            'membership' => $this->membership,
            'membership_sha256' => $this->membershipSha256,
            'runs' => array_combine(array_map('strval', array_keys($this->runs)), array_values($this->runs)),
        ]).PHP_EOL;
    }

    /**
     * Deduplicated and sorted, so the same runs always hash the same.
     *
     * @param  array<mixed>  $runIds
     * @return list<int>
     */
    public static function membership(array $runIds): array
    {
        $membership = array_values(array_unique(array_filter(array_map('intval', $runIds), static fn (int $id): bool => $id > 0)));
        sort($membership);

        return $membership;
    }
}
